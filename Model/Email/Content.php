<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model\Email;

use Magento\Framework\Mail\MessageInterface;
use OneTrace\Resource\Emails;

/**
 * A rendered Magento email as POST /emails fields, and its recipients. Magento 2.4.8+ builds Symfony Mime messages
 * (the parts are read from the Symfony message: its MimeMessage keeps only the first text part), 2.4.7 —
 * Laminas Mail messages.
 */
class Content
{
    public const MAX_ATTACHMENTS = 5;

    public const MAX_ATTACHMENT_BYTES = 5 * 1024 * 1024;

    /** Attachment types the platform accepts, by file extension for files sent as application/octet-stream. */
    private const TYPES = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'txt' => 'text/plain', 'csv' => 'text/csv', 'ics' => 'text/calendar'];

    /**
     * Subject, html, text, from_name, reply_to and attachments; null — the email cannot go as is (inline images,
     * an attachment of another type or above the limits, an unknown message format).
     *
     * @return array{subject: string, html: string, text?: string, from_name?: string, reply_to?: string, attachments?: list<array{name: string, type: string, content: string}>}|null
     */
    public function read(MessageInterface $message): ?array
    {
        $parts = method_exists($message, 'getSymfonyMessage') ? $this->symfonyParts($message->getSymfonyMessage()) : $this->laminasParts($message);

        if ($parts === null) {
            return null;
        }

        $html = $parts['html'];
        $text = $parts['text'];

        if ($html === '' && $text === '') {
            return null;
        }

        // Images embedded in the message (cid:) are not sent by the platform.
        if (preg_match('/["\'(]\s*cid:/i', $html) === 1) {
            return null;
        }

        $attachments = [];
        $bytes = 0;

        foreach ($parts['files'] as [$name, $type, $contents]) {
            $type = $this->type($name, $type);
            $bytes += strlen($contents);

            if ($type === null || count($attachments) >= self::MAX_ATTACHMENTS || $bytes > self::MAX_ATTACHMENT_BYTES) {
                return null;
            }

            $attachments[] = Emails::attachment($name !== '' ? $name : 'attachment', $type, $contents);
        }

        $subject = trim((string) $message->getSubject());

        if ($subject === '') {
            return null;
        }

        $content = [
            'subject' => $subject,
            'html' => $html !== '' ? $html : '<div style="white-space:pre-wrap">' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>',
        ];

        if ($text !== '') {
            $content['text'] = $text;
        }

        $from = method_exists($message, 'getFrom') ? (array) $message->getFrom() : [];
        $replyTo = method_exists($message, 'getReplyTo') ? (array) $message->getReplyTo() : [];
        $fromName = isset($from[0]) ? trim((string) $from[0]->getName()) : '';
        $replyAddress = isset($replyTo[0]) ? trim((string) $replyTo[0]->getEmail()) : '';

        if ($fromName !== '') {
            $content['from_name'] = mb_substr($fromName, 0, 100);
        }

        if ($replyAddress !== '' && filter_var($replyAddress, FILTER_VALIDATE_EMAIL) !== false) {
            $content['reply_to'] = $replyAddress;
        }

        if ($attachments !== []) {
            $content['attachments'] = $attachments;
        }

        return $content;
    }

    /**
     * Recipients with their header, each address once: [email (lower case), "to"|"cc"|"bcc"].
     *
     * @return list<array{0: string, 1: string}>
     */
    public function recipients(MessageInterface $message): array
    {
        $recipients = [];

        foreach (['to' => 'getTo', 'cc' => 'getCc', 'bcc' => 'getBcc'] as $header => $method) {
            foreach (method_exists($message, $method) ? (array) $message->{$method}() : [] as $address) {
                $email = strtolower(trim((string) $address->getEmail()));

                if ($email !== '' && !isset($recipients[$email])) {
                    $recipients[$email] = [$email, $header];
                }
            }
        }

        return array_values($recipients);
    }

    /**
     * Leaves only these recipients in the message, so Magento sends it natively to the ones OneTrace did not
     * accept; false — the message could not be changed.
     *
     * @param list<string> $keep lower case addresses
     */
    public function keepRecipients(MessageInterface $message, array $keep): bool
    {
        try {
            if (method_exists($message, 'getSymfonyMessage')) {
                $headers = $message->getSymfonyMessage()->getHeaders();

                foreach (['To', 'Cc', 'Bcc'] as $name) {
                    $header = $headers->get($name);

                    if (!$header instanceof \Symfony\Component\Mime\Header\MailboxListHeader) {
                        continue;
                    }

                    $left = array_values(array_filter($header->getAddresses(), static fn (\Symfony\Component\Mime\Address $address): bool => in_array(strtolower($address->getAddress()), $keep, true)));
                    $headers->remove($name);

                    if ($left !== []) {
                        $headers->addMailboxListHeader($name, $left);
                    }
                }

                return true;
            }

            if ($message instanceof \Magento\Framework\Mail\Message) {
                $laminas = (new \ReflectionProperty(\Magento\Framework\Mail\Message::class, 'zendMessage'))->getValue($message);

                if (!is_object($laminas) || !method_exists($laminas, 'getTo')) {
                    return false;
                }

                foreach ([$laminas->getTo(), $laminas->getCc(), $laminas->getBcc()] as $list) {
                    foreach (iterator_to_array($list) as $address) {
                        if (!in_array(strtolower((string) $address->getEmail()), $keep, true)) {
                            $list->delete($address->getEmail());
                        }
                    }
                }

                return true;
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /**
     * @return array{html: string, text: string, files: list<array{0: string, 1: string, 2: string}>}|null
     */
    private function symfonyParts(object $message): ?array
    {
        $parts = ['html' => '', 'text' => '', 'files' => []];

        if ($message instanceof \Symfony\Component\Mime\Email) {
            $parts['html'] = (string) $message->getHtmlBody();
            $parts['text'] = (string) $message->getTextBody();

            foreach ($message->getAttachments() as $attachment) {
                if ($attachment->getDisposition() === 'inline' || $attachment->hasContentId()) {
                    return null;
                }

                $parts['files'][] = [(string) $attachment->getFilename(), $attachment->getContentType(), $attachment->getBody()];
            }

            return $parts;
        }

        if (!$message instanceof \Symfony\Component\Mime\Message || $message->getBody() === null) {
            return null;
        }

        return $this->symfonyPart($message->getBody(), $parts) ? $parts : null;
    }

    /**
     * @param array{html: string, text: string, files: list<array{0: string, 1: string, 2: string}>} $parts
     */
    private function symfonyPart(\Symfony\Component\Mime\Part\AbstractPart $part, array &$parts): bool
    {
        if ($part instanceof \Symfony\Component\Mime\Part\AbstractMultipartPart) {
            foreach ($part->getParts() as $child) {
                if (!$this->symfonyPart($child, $parts)) {
                    return false;
                }
            }

            return true;
        }

        if ($part instanceof \Symfony\Component\Mime\Part\DataPart) {
            if ($part->getDisposition() === 'inline' || $part->hasContentId()) {
                return false;
            }

            $parts['files'][] = [(string) $part->getFilename(), $part->getContentType(), $part->getBody()];

            return true;
        }

        if ($part instanceof \Symfony\Component\Mime\Part\TextPart) {
            $type = $part->getMediaType() . '/' . $part->getMediaSubtype();

            if ($type === 'text/html' && $parts['html'] === '') {
                $parts['html'] = $part->getBody();

                return true;
            }

            if ($type === 'text/plain' && $parts['text'] === '') {
                $parts['text'] = $part->getBody();

                return true;
            }
        }

        return false;
    }

    /**
     * Parts of a Laminas message (Magento 2.4.7): Laminas or Magento MIME parts with the same methods.
     *
     * @return array{html: string, text: string, files: list<array{0: string, 1: string, 2: string}>}|null
     */
    private function laminasParts(MessageInterface $message): ?array
    {
        if (!method_exists($message, 'getMessageBody')) {
            return null;
        }

        $parts = ['html' => '', 'text' => '', 'files' => []];

        foreach ($message->getMessageBody()->getParts() as $part) {
            if (!is_object($part) || !method_exists($part, 'getType') || !method_exists($part, 'getRawContent')) {
                return null;
            }

            $type = strtolower(trim(explode(';', self::property($part, 'getType'))[0]));
            $disposition = strtolower(self::property($part, 'getDisposition'));
            $name = self::property($part, 'getFileName');

            if ($disposition === 'inline' && $name !== '') {
                return null;
            }

            if ($disposition !== 'attachment' && $name === '' && in_array($type, ['text/html', 'text/plain'], true)) {
                $key = $type === 'text/html' ? 'html' : 'text';

                if ($parts[$key] !== '') {
                    return null;
                }

                $parts[$key] = (string) $part->getRawContent();

                continue;
            }

            if (str_starts_with($type, 'multipart/')) {
                return null;
            }

            $parts['files'][] = [$name, $type, (string) $part->getRawContent()];
        }

        return $parts;
    }

    /**
     * A property of a MIME part: Magento 2.4.7 MimePart declares string getters that return null for unset ones.
     */
    private static function property(object $part, string $getter): string
    {
        try {
            return method_exists($part, $getter) ? trim((string) $part->{$getter}()) : '';
        } catch (\TypeError) {
            return '';
        }
    }

    private function type(string $name, string $type): ?string
    {
        $type = strtolower(trim(explode(';', $type)[0]));

        if (in_array($type, self::TYPES, true)) {
            return $type;
        }

        // A file without a precise type (application/octet-stream): by its extension.
        if ($type === '' || $type === 'application/octet-stream') {
            return self::TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null;
        }

        return null;
    }
}
