<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model;

use Magento\Framework\Escaper;

/**
 * The AI block of the quick search results page (AI search of the platform): Plugin\Search remembers the answer of
 * AI for the first page, Plugin\SearchAiBlock puts <div data-cdp-search-ai> above the results. The tracker writes the
 * short AI answer there and draws the chips of what AI understood and the refinements; they open the results again
 * with ai_state (a removed chip), ai_refine + ai_previous (a refinement) or ai=0 (the usual search).
 */
class SearchAiBlock
{
    /** Parameters of the links of the block (the tracker sets them). */
    public const PARAMS = ['ai', 'ai_state', 'ai_refine', 'ai_previous'];

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    public function __construct(private readonly Escaper $escaper)
    {
    }

    /**
     * @param array<string, mixed>|null $data url, turns, state, understood, refinements, relaxed, products
     */
    public function set(?array $data): void
    {
        $this->data = $data;
    }

    /** The markup of the block, once; '' — no AI results on this page. */
    public function take(): string
    {
        if ($this->data === null) {
            return '';
        }

        $html = '<div class="onetrace-search-ai" data-cdp-search-ai="' . $this->escaper->escapeHtmlAttr((string) json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false) . '"></div>';
        $this->data = null;

        return $html;
    }

    /**
     * A parse in a link of the block: base64url of JSON; null — none or broken.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '' || strlen($raw) > 8000 || preg_match('/^[A-Za-z0-9_-]+$/', $raw) !== 1) {
            return null;
        }

        $json = base64_decode(strtr($raw, '-_', '+/'), true);
        $value = $json !== false ? json_decode($json, true) : null;

        return is_array($value) ? $value : null;
    }
}
