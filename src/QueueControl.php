<?php
declare(strict_types=1);
namespace Kienzlefon;

use React\Promise\PromiseInterface;
use function React\Promise\resolve;
use function React\Promise\reject;

/**
 * Runtime queue participation for known local endpoints.
 * AMI changes happen on the already connected middleware session; no shell or reload.
 */
final class QueueControl
{
    private const QUEUES = ['praxis', 'kienzlefon-sonder'];
    private array $desired = [];
    private bool $busy = false;

    public function __construct(
        private array $config,
        private array $extensions,
        private Telephony $telephony,
        private \Closure $ami,
        private bool $demo = false
    ) {
        foreach (self::QUEUES as $index => $queue) {
            $defaults = $config[$index === 0 ? 'default_practice' : 'default_special'];
            foreach ($extensions as $extension => $_label) {
                $this->desired[$queue][(string)$extension] = in_array((string)$extension, $defaults, true);
            }
        }
        if (!$config['enabled']) return;
        $path = $config['state_file'];
        if (is_link($path)) throw new \RuntimeException('Queue-Statusdatei darf kein Symlink sein.');
        if (!is_file($path)) return;
        $data = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_keys($data) !== self::QUEUES) {
            throw new \RuntimeException('Ungültiger gespeicherter Queue-Status.');
        }
        foreach (self::QUEUES as $queue) {
            if (!is_array($data[$queue]) || array_keys($data[$queue]) !== array_keys($this->desired[$queue])) {
                throw new \RuntimeException('Queue-Nebenstellen haben sich seit der Speicherung geändert; bitte Statusdatei prüfen.');
            }
            foreach ($data[$queue] as $value) {
                if (!is_bool($value)) throw new \RuntimeException('Ungültiger gespeicherter Queue-Schalter.');
            }
            if (!in_array(true, $data[$queue], true)) {
                throw new \RuntimeException('Mindestens eine Queue-Nebenstelle muss aktiviert bleiben.');
            }
        }
        $this->desired = $data;
    }

    public function view(): array
    {
        return [
            'enabled' => (bool)$this->config['enabled'],
            'ready' => $this->config['enabled'] && !$this->busy && $this->telephony->queuePresent('praxis')
                && $this->telephony->queuePresent('kienzlefon-sonder'),
            'selection' => $this->desired,
            'actual' => [
                'praxis' => $this->telephony->queueMemberStates('praxis'),
                'kienzlefon-sonder' => $this->telephony->queueMemberStates('kienzlefon-sonder'),
            ],
        ];
    }

    private function interface(string $extension): string
    {
        return 'Local/' . $extension . '@kienzlefon-queue-member';
    }

    private function actionFor(string $queue, string $extension, bool $enabled): ?array
    {
        $actual = $this->telephony->queueMemberStates($queue);
        if (array_key_exists($extension, $actual)) {
            if ($actual[$extension] === $enabled) return null;
            return ['QueuePause', [
                'Queue' => $queue, 'Interface' => $this->interface($extension),
                'Paused' => $enabled ? 'false' : 'true',
            ]];
        }
        if (!$enabled) return null;
        // Kienzlefon's normal progressive rule exposes penalties 0..3.
        // Extension 300 is an optional fourth-stage participant in practice.
        $index = array_search($extension, array_map('strval', array_keys($this->extensions)), true);
        return ['QueueAdd', [
            'Queue' => $queue, 'Interface' => $this->interface($extension),
            'Penalty' => $queue === 'praxis' ? (string)min(3, (int)$index) : '0',
            'MemberName' => 'Telefon ' . $extension,
            'StateInterface' => 'PJSIP/' . $extension,
            'Paused' => 'false',
        ]];
    }

    public function change(string $queue, string $extension, bool $enabled): PromiseInterface
    {
        if (!$this->config['enabled']) return reject(new \InvalidArgumentException('Queue-Steuerung ist deaktiviert.'));
        if (!in_array($queue, self::QUEUES, true) || !array_key_exists($extension, $this->desired[$queue] ?? [])) {
            return reject(new \InvalidArgumentException('Nebenstelle oder Warteschlange nicht freigegeben.'));
        }
        if ($this->busy || !$this->telephony->queuePresent($queue)) {
            return reject(new \RuntimeException('Warteschlange nicht aktuell oder Änderung läuft bereits.'));
        }
        $next = $this->desired;
        $next[$queue][$extension] = $enabled;
        if (!in_array(true, $next[$queue], true)) {
            return reject(new \InvalidArgumentException('Die letzte aktive Nebenstelle darf nicht deaktiviert werden.'));
        }
        if ($next === $this->desired) return resolve($this->view());
        $command = $this->actionFor($queue, $extension, $enabled);
        $this->busy = true;
        $request = $command ? ($this->ami)($command[0], $command[1]) : resolve(null);
        return $request->then(function () use ($next) {
            try {
                $this->persist($next);
                $this->desired = $next;
                return $this->view();
            } finally {
                $this->busy = false;
            }
        }, function (\Throwable $e) {
            $this->busy = false;
            throw $e;
        });
    }

    /** Reconcile after each fresh AMI snapshot, including after an Asterisk restart. */
    public function restore(): void
    {
        if (!$this->config['enabled'] || $this->busy
            || !$this->telephony->queuePresent('praxis')
            || !$this->telephony->queuePresent('kienzlefon-sonder')) return;
        $operations = [];
        foreach ($this->desired as $queue => $extensions) {
            foreach ($extensions as $extension => $enabled) {
                $action = $this->actionFor($queue, (string)$extension, $enabled);
                if ($action !== null) $operations[] = $action;
            }
        }
        if (!$operations) return;
        $this->busy = true;
        $promise = resolve(null);
        foreach ($operations as [$action, $fields]) {
            $promise = $promise->then(fn() => ($this->ami)($action, $fields));
        }
        $promise->then(
            function () { $this->busy = false; },
            function (\Throwable $e) {
                $this->busy = false;
                error_log('Kienzlefon Queue-Wiederherstellung fehlgeschlagen: ' . $e->getMessage());
            }
        );
    }

    private function persist(array $value): void
    {
        $path = $this->config['state_file'];
        $parent = dirname($path);
        if (!is_dir($parent) || is_link($path)) throw new \RuntimeException('Queue-Statuspfad nicht sicher.');
        $temporary = tempnam($parent, '.queue-control-');
        if ($temporary === false) throw new \RuntimeException('Queue-Statusdatei nicht beschreibbar.');
        try {
            chmod($temporary, 0600);
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false || !rename($temporary, $path)) {
                throw new \RuntimeException('Queue-Status konnte nicht gespeichert werden.');
            }
        } finally {
            if (is_file($temporary)) @unlink($temporary);
        }
    }
}
