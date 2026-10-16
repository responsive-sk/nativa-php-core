<?php

declare(strict_types = 1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ModelPayloadContractTest — the model's refusal event against the code's one
 * payload vocabulary.
 *
 * Measured 2026-10-16, before this round, for `users.authorization_denied`:
 *
 *     model payload      -> required, path, user_id, ip            (4)
 *     payloadKeys()      -> path, required, userId, roles, ip      (5)
 *     in code, not model -> roles
 *     in model, not code -> (none)
 *     the model's own DESCRIPTION lists roles                      (yes)
 *     the model's own PAYLOAD does not                             (the defect)
 *
 * That is the model-side remainder of the defect the code side closed the same
 * day: `payloadKeys()` declared `ip` for both refusal types while the Audit
 * handler read it and every emit site sent it — a key present in prose and
 * absent from the list, which is exactly the shape here. Both emit sites DO
 * send `roles` (`AuthMiddleware::recordDenial()`, `SecurityEventServices::
 * bulkRefused()`), and `SecurityEvents` is the one owner of that vocabulary
 * ("no emit site and no handler spells an event type or invents a payload key
 * of its own"), so the model was the only side still describing four keys.
 *
 * Nothing else read this either: `ModelReferenceContractTest` beside it
 * resolves the `error_ref`s in `lekalo/authorization.yaml`, and
 * `ModelCatalogueContractTest` compares the model's module SET. Neither opens
 * an event's `payload`.
 *
 * Deliberately dumb, in the shape of its neighbours, and for a harder reason
 * than theirs: `SecurityEvents` lives in an OPTIONAL module, and `phpstan.neon`
 * analyses `tests/` while `src/Modules/<Name>` outside Core/Database is
 * operator state. Loading that class here would make the kernel-only gate fail
 * in a tree where Outbox is not installed — so the file is read as TEXT, the
 * way the module scanner reads a class it will not load and the way
 * `ModelReferenceContractTest` reads a YAML file with no parser.
 *
 * @internal
 *
 * @coversNothing
 */
final class ModelPayloadContractTest extends TestCase
{
    /**
     * The event this binding was written for, named rather than matched, so a
     * rewrite that drops the vocabulary from a description cannot quietly
     * narrow what this file covers — the same reason `ModelCatalogueContractTest`
     * names the kernel slices and the catalogue fixture instead of excusing
     * them with a pattern.
     */
    private const string ANCHOR_EVENT = 'users.authorization_denied';

    /**
     * The constant the anchor event is recorded as, and the type it carries.
     */
    private const string ANCHOR_CONSTANT = 'AUTHZ_DENIED';

    /**
     * Every event the model declares, with its payload key names.
     *
     * `lekalo/modules/<module>/events.yaml`, JSON content despite the suffix.
     * Payload shape is asserted as it is read, because the key sets below are
     * compared after a snake_case→camelCase fold: a name that is not snake_case
     * would be folded into something the code never declares, and the failure
     * would name the code instead of the model.
     *
     * @return array<string, array{file: string, keys: list<string>, description: string}>
     */
    private static function modelledEvents(): array
    {
        $files = glob(dirname(__DIR__, 2) . '/lekalo/modules/*/events.yaml');

        self::assertIsArray($files, 'lekalo/modules/*/events.yaml is not readable.');
        self::assertNotEmpty($files, 'No model event slices found — lekalo/modules is empty or moved.');

        $events = [];

        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);

            self::assertIsArray($decoded, sprintf('%s is not a JSON model document.', $file));

            $definitions = $decoded['definitions'] ?? [];

            self::assertIsArray($definitions, sprintf('%s has no definitions list.', $file));

            foreach ($definitions as $definition) {
                self::assertIsArray($definition, sprintf('%s has a non-object definition.', $file));
                self::assertSame(
                    'event',
                    $definition['kind'] ?? null,
                    sprintf('%s holds a definition that is not an event.', $file),
                );

                $id = $definition['id'] ?? null;
                self::assertIsString($id, sprintf('%s has an event with no id.', $file));
                self::assertArrayNotHasKey($id, $events, sprintf('Event "%s" is declared twice.', $id));

                $payload = $definition['payload'] ?? [];
                self::assertIsArray($payload, sprintf('Event "%s" has a non-list payload.', $id));

                $keys = [];
                $description = $definition['description'] ?? '';

                foreach ($payload as $entry) {
                    self::assertIsArray($entry, sprintf('Event "%s" has a non-object payload entry.', $id));

                    $name = $entry['name'] ?? null;

                    self::assertIsString($name, sprintf('Event "%s" has a payload entry with no name.', $id));
                    self::assertMatchesRegularExpression(
                        '/^[a-z][a-z0-9_]*$/',
                        $name,
                        sprintf(
                            'Event "%s" declares the payload key "%s". The model names payload keys in'
                            . ' snake_case and the code names them in camelCase; a key outside that shape'
                            . ' cannot be folded into the other side without guessing, which is not'
                            . ' something this check may do.',
                            $id,
                            $name,
                        ),
                    );
                    self::assertNotContains(
                        $name,
                        $keys,
                        sprintf('Event "%s" declares the payload key "%s" twice.', $id, $name),
                    );

                    $keys[] = $name;
                }

                self::assertNotEmpty(
                    $keys,
                    sprintf(
                        'Event "%s" declares no payload key at all. An event nobody can read is'
                        . ' documentation-shaped, not a contract — if it really carries nothing,'
                        . ' say so in the description rather than leaving the list empty here.',
                        $id,
                    ),
                );

                $events[$id] = [
                    'file'        => $file,
                    'keys'        => $keys,
                    'description' => is_string($description) ? $description : '',
                ];
            }
        }

        return $events;
    }

    /**
     * The code's event-type vocabulary and its payload keys, read as TEXT.
     *
     * From `modules/Outbox/Domain/SecurityEvents.php` — the committed catalogue
     * copy, which is the source the installed slice is copied FROM, so reading
     * it cannot observe a different vocabulary than the one an emit site uses.
     *
     * @return array{constants: array<string, string>, keys: array<string, list<string>>}
     */
    private static function securityVocabulary(): array
    {
        $file = dirname(__DIR__, 2) . '/modules/Outbox/Domain/SecurityEvents.php';

        self::assertFileExists(
            $file,
            'modules/Outbox/Domain/SecurityEvents.php is missing — the catalogue is the vocabulary owner.',
        );

        $source = (string) file_get_contents($file);

        $constants = [];

        if (preg_match_all("/public const string (\w+) = '([^']*)';/", $source, $declarations, PREG_SET_ORDER) > 0) {
            foreach ($declarations as $declaration) {
                $constants[$declaration[1]] = $declaration[2];
            }
        }

        // Only the payloadKeys() body: `self::X => [...]` appears nowhere else in
        // the file (measured: the constants are `= '…'`, and `all()` is a plain
        // list), so the slice keeps a future match arm somewhere else from being
        // counted as a payload declaration.
        $body = false === ($start = strpos($source, 'function payloadKeys'))
            ? ''
            : substr($source, $start);

        $keys = [];

        if (preg_match_all('/self::(\w+)\s*=>\s*\[([^\]]*)\]/', $body, $arms, PREG_SET_ORDER) > 0) {
            foreach ($arms as $arm) {
                preg_match_all("/'([^']+)'/", $arm[2], $named);
                $keys[$constants[$arm[1]] ?? $arm[1]] = $named[1];
            }
        }

        self::assertNotEmpty(
            $constants,
            'No SecurityEvents constants parsed out of the catalogue file — it was rewritten, and'
            . ' this check is now reading nothing.',
        );
        self::assertNotEmpty(
            $keys,
            'No payloadKeys() arms parsed out of the catalogue file — the match was rewritten, and'
            . ' this check is now reading nothing.',
        );

        return ['constants' => $constants, 'keys' => $keys];
    }

    /**
     * The model key names, folded onto the code's camelCase form.
     *
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private static function asCamelCase(array $keys): array
    {
        return array_map(
            static fn (string $key): string => (string) preg_replace_callback(
                '/_([a-z])/',
                static fn (array $match): string => strtoupper($match[1]),
                $key,
            ),
            $keys,
        );
    }

    #[Test]
    public function everyModelledEventThatNamesASecurityTypeDeclaresTheKeysTheCodeDeclares(): void
    {
        $vocabulary = self::securityVocabulary();
        $events = self::modelledEvents();

        $checked = 0;

        foreach ($events as $id => $event) {
            // The model states the code type it is recorded as, e.g.
            // "Recorded through the Outbox as SecurityEvents::AUTHZ_DENIED".
            if (1 !== preg_match('/SecurityEvents::(\w+)/', $event['description'], $named)) {
                continue;
            }

            $constant = $named[1];
            $eventType = $vocabulary['constants'][$constant] ?? null;

            self::assertNotNull(
                $eventType,
                sprintf(
                    'Event "%s" (%s) says it is recorded as SecurityEvents::%s, which the code no'
                    . ' longer declares. Either the constant was renamed and the description was'
                    . ' not, or the reference was never real — the constants the file declares are:'
                    . ' %s.',
                    $id,
                    $event['file'],
                    $constant,
                    implode(', ', array_keys($vocabulary['constants'])),
                ),
            );

            $declared = $vocabulary['keys'][$eventType] ?? null;

            self::assertNotNull(
                $declared,
                sprintf(
                    'SecurityEvents::%s resolves to "%s", which payloadKeys() has no arm for, so'
                    . ' the model cannot be checked against it.',
                    $constant,
                    $eventType,
                ),
            );

            $modelKeys = self::asCamelCase($event['keys']);
            sort($modelKeys);
            sort($declared);

            self::assertSame(
                $declared,
                $modelKeys,
                sprintf(
                    'Event "%s" declares payload keys [%s], but SecurityEvents::payloadKeys(\'%s\')'
                    . ' declares [%s]. The code is the owner of this vocabulary, so the model is the'
                    . ' side to correct — and the model must describe what is sent, not what would be'
                    . ' nicer to send. `roles` reached this list last: it was in the event\'s own'
                    . ' description and missing from its payload (measured 2026-10-16).',
                    $id,
                    implode(', ', $event['keys']),
                    $eventType,
                    implode(', ', $declared),
                ),
            );

            $checked++;
        }

        self::assertGreaterThan(
            0,
            $checked,
            'No modelled event names a SecurityEvents constant, so this check proves nothing.'
            . ' That is a fact about the model, not about the code: the anchor event'
            . ' `users.authorization_denied` names one, and if it stopped, say so deliberately.',
        );
    }

    #[Test]
    public function theAnchorRefusalEventIsBoundToTheCodeVocabulary(): void
    {
        $vocabulary = self::securityVocabulary();
        $events = self::modelledEvents();

        self::assertArrayHasKey(
            self::ANCHOR_EVENT,
            $events,
            sprintf(
                'The anchor event "%s" is gone from the model. This file was written for it — a'
                . ' rename is a decision to make here, not a diff to accept.',
                self::ANCHOR_EVENT,
            ),
        );

        $anchor = $events[self::ANCHOR_EVENT];

        self::assertStringContainsString(
            'SecurityEvents::' . self::ANCHOR_CONSTANT,
            $anchor['description'],
            sprintf(
                'The anchor event "%s" no longer names SecurityEvents::%s in its description, so'
                . ' the payload comparison above skips it and the one event this binding exists'
                . ' for is unchecked.',
                self::ANCHOR_EVENT,
                self::ANCHOR_CONSTANT,
            ),
        );

        $eventType = $vocabulary['constants'][self::ANCHOR_CONSTANT] ?? null;
        self::assertNotNull(
            $eventType,
            sprintf('SecurityEvents::%s is not declared in the catalogue copy.', self::ANCHOR_CONSTANT),
        );

        $modelKeys = self::asCamelCase($anchor['keys']);
        sort($modelKeys);
        $declared = $vocabulary['keys'][$eventType] ?? [];
        sort($declared);

        self::assertSame(
            $declared,
            $modelKeys,
            sprintf(
                'The refused-authorization event must carry exactly the keys payloadKeys(\'%s\')'
                . ' declares, whatever the generic loop above concludes.',
                $eventType,
            ),
        );
    }
}
