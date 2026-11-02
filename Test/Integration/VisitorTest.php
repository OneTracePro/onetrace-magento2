<?php

declare(strict_types=1);

namespace OneTrace\Connector\Test\Integration;

use Magento\Config\Model\ResourceModel\Config as ConfigWriter;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use OneTrace\Connector\CustomerData\Visitor;
use OneTrace\Connector\Model\Connection;
use OneTrace\Connector\Test\TestCase;

/**
 * The private section "onetrace": the signed-in customer for the tracker with the signature of the id
 * (ADR-158 of the platform).
 */
final class VisitorTest extends TestCase
{
    public function testSignsTheIdOfTheSignedInCustomerWithTheIdentityKey(): void
    {
        $customer = $this->om->get(CustomerInterfaceFactory::class)->create();
        $customer->setWebsiteId(1)->setStoreId(1)->setEmail('visitor-' . bin2hex(random_bytes(4)) . '@example.com')->setFirstname('Mary')->setLastname('Tester');
        $id = (string) $this->om->get(AccountManagementInterface::class)->createAccount($customer)->getId();
        $session = $this->om->get(CustomerSession::class);
        $session->setCustomerId((int) $id);

        try {
            self::assertSame(['id' => $id, 'hash' => ''], array_intersect_key($this->om->create(Visitor::class)->getSectionData(), ['id' => 1, 'hash' => 1]), 'Without the identity key.');

            $this->om->get(ConfigWriter::class)->saveConfig('onetrace/connection/identity_key', $this->om->get(EncryptorInterface::class)->encrypt('cdp_ik_test'));
            $this->om->get(ReinitableConfigInterface::class)->reinit();
            $this->om->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('config');
            $this->om->get(Connection::class)->reset(['transport' => $this->platform]);

            // The same HMAC as IdentityVerification::hash() of the platform.
            self::assertSame(['id' => $id, 'hash' => hash_hmac('sha256', $id, 'cdp_ik_test')], array_intersect_key($this->om->create(Visitor::class)->getSectionData(), ['id' => 1, 'hash' => 1]));

            $session->setCustomerId(null);
            self::assertSame(['id' => '', 'hash' => ''], array_intersect_key($this->om->create(Visitor::class)->getSectionData(), ['id' => 1, 'hash' => 1]), 'A guest.');
        } finally {
            $session->setCustomerId(null);
        }
    }
}
