<?php

declare(strict_types=1);

namespace HamadaEmamTech\Esign\Tests\Unit;

use HamadaEmamTech\Esign\Discovery\ProviderRegistry;
use HamadaEmamTech\Esign\Envelope\Document;
use HamadaEmamTech\Esign\Envelope\Envelope;
use HamadaEmamTech\Esign\Envelope\EnvelopeStatus;
use HamadaEmamTech\Esign\Envelope\Signer;
use HamadaEmamTech\Esign\Esign;
use HamadaEmamTech\Esign\Exceptions\ProviderNotConfigured;
use HamadaEmamTech\Esign\Exceptions\ProviderNotInstalled;
use HamadaEmamTech\Esign\Testing\FakeProvider;
use HamadaEmamTech\Esign\Tests\Fixtures\DummyFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EsignTest extends TestCase
{
    private function registry(): ProviderRegistry
    {
        return new ProviderRegistry(__DIR__ . '/../Fixtures/installed.json');
    }

    private function envelope(): Envelope
    {
        return new Envelope([new Document('contract.pdf', '%PDF-1.7 test')], [Signer::nafath('1012345678', 'Ahmed', 'ahmed@example.sa')]);
    }

    #[Test]
    public function an_installed_provider_is_discovered_from_composer_metadata(): void
    {
        $esign = new Esign(['default' => 'sadq', 'providers' => ['sadq' => ['client_id' => 'abc']]], $this->registry());

        self::assertContains('sadq', $esign->installed());
        $esign->send($this->envelope());
        self::assertSame(['client_id' => 'abc'], DummyFactory::$lastConfig);
    }

    #[Test]
    public function a_missing_released_provider_tells_you_how_to_install_it(): void
    {
        $esign = new Esign(['default' => 'sadq', 'providers' => ['sadq' => []]], new ProviderRegistry('/nonexistent/installed.json'));

        $this->expectException(ProviderNotInstalled::class);
        $this->expectExceptionMessage('Run: vendor/bin/esign add sadq (or: composer require hamada-emam-tech/esign-sadq)');
        $esign->provider();
    }

    #[Test]
    public function a_planned_provider_says_it_is_not_released_yet(): void
    {
        $esign = new Esign(['default' => 'emdha', 'providers' => ['emdha' => []]], $this->registry());

        $this->expectException(ProviderNotInstalled::class);
        $this->expectExceptionMessage('planned but not released yet');
        $esign->provider();
    }

    #[Test]
    public function an_unknown_provider_lists_the_known_ones(): void
    {
        $esign = new Esign(['default' => 'nope', 'providers' => ['nope' => []]], $this->registry());

        $this->expectException(ProviderNotInstalled::class);
        $this->expectExceptionMessageMatches('/Unknown provider "nope". Known providers: fake, sadq, /');
        $esign->provider();
    }

    #[Test]
    public function an_installed_but_unconfigured_provider_fails_clearly(): void
    {
        $esign = new Esign(['default' => 'sadq'], $this->registry());

        $this->expectException(ProviderNotConfigured::class);
        $esign->provider();
    }

    #[Test]
    public function no_default_and_no_name_fails_clearly(): void
    {
        $this->expectException(ProviderNotConfigured::class);
        (new Esign([], $this->registry()))->provider();
    }

    #[Test]
    public function providers_can_be_switched_at_runtime_and_are_built_once(): void
    {
        $esign = new Esign(['default' => 'fake', 'providers' => ['sadq' => []]], $this->registry());

        self::assertSame('fake', $esign->provider()->name());
        self::assertSame($esign->provider('sadq'), $esign->provider('SADQ'));
    }

    #[Test]
    public function a_provider_can_be_registered_by_hand_and_wins_over_discovery(): void
    {
        $mine = new FakeProvider('mine');
        $esign = (new Esign(['default' => 'sadq', 'providers' => ['sadq' => []]], $this->registry()))
            ->extend('sadq', fn (array $config) => $mine);

        self::assertSame($mine, $esign->provider());
    }

    #[Test]
    public function the_fake_provider_runs_a_full_lifecycle(): void
    {
        $esign = new Esign(['default' => 'fake'], $this->registry());
        /** @var FakeProvider $fake */
        $fake = $esign->provider();

        $sent = $esign->send($this->envelope());
        self::assertSame(EnvelopeStatus::Sent, $esign->status($sent->envelopeId));
        self::assertArrayHasKey('ahmed@example.sa', $sent->signingUrls);

        [$headers, $body] = $fake->webhookFor($sent->envelopeId);
        self::assertSame($sent->envelopeId, $esign->parseWebhook($headers, $body)->envelopeId);

        $fake->sign($sent->envelopeId);
        self::assertTrue($esign->status($sent->envelopeId)->isFinal());
        self::assertStringStartsWith('%PDF', $esign->download($sent->envelopeId));
        $fake->assertSent(fn (Envelope $e) => $e->signers[0]->nationalId === '1012345678');
    }
}
