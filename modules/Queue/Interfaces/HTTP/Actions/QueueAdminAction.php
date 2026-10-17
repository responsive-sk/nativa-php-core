<?php

declare(strict_types = 1);

namespace Modules\Queue\Interfaces\HTTP\Actions;

use Modules\Audit\Interfaces\HTTP\AuditServices;
use Modules\Cardboard\Interfaces\HTTP\AdminLayout;
use Modules\Core\Application\Services\CacheInvalidator;
use Modules\Core\Infrastructure\Http\Request;
use Modules\Core\Infrastructure\Http\Response;
use Modules\Core\Interfaces\HTTP\ResolvesRouteUrls;
use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Queue\Interfaces\HTTP\QueueServices;

/**
 * Queue admin action — job management at /mark/queue.
 *
 * The /mark prefix is already protected by AuthMiddleware (admin role
 * required). Lists jobs pending-first with a queue filter, shows one job
 * with its JSON args, deletes jobs via POST; every state change redirects
 * back to the list. Failed-job retry/delete arrive in the next task on
 * this same class.
 */
#[Route(path: '/mark/queue', method: 'GET', name: 'mark.queue')]
#[Route(path: '/mark/queue/{id}', method: 'GET', name: 'mark.queue.detail', handlerMethod: 'detail')]
#[Route(path: '/mark/queue/{id}/delete', method: 'POST', name: 'mark.queue.delete', handlerMethod: 'delete')]
#[Route(path: '/mark/queue/failed/{id}/retry', method: 'POST', name: 'mark.queue.failed.retry', handlerMethod: 'retryFailed')]
#[Route(path: '/mark/queue/failed/{id}/delete', method: 'POST', name: 'mark.queue.failed.delete', handlerMethod: 'deleteFailed')]
final class QueueAdminAction
{
    use ResolvesRouteUrls;

    public function handle(Request $request): Response
    {
        return Response::html($this->listPage($request));
    }

    public function detail(Request $request): Response
    {
        $id = (string) ($request->routeParam('id') ?? '');
        $job = '' !== $id ? QueueServices::queue()->findJob($id) : null;

        if (null === $job) {
            return Response::html(
                AdminLayout::render('Job not found', '<p class="m-muted">No job with this id.</p>'),
                404,
            );
        }

        return Response::html($this->detailPage($job));
    }

    public function delete(Request $request): Response
    {
        $id = $request->routeParam('id');

        if (null !== $id && '' !== $id) {
            QueueServices::queue()->delete($id);
            CacheInvalidator::flush();
            AuditServices::log('queue.delete', 'job:' . $id, 'Deleted job ' . $id, $request->getClientIp());
        }

        return Response::redirect($this->url('mark.queue'));
    }

    public function retryFailed(Request $request): Response
    {
        $id = $request->routeParam('id');

        if (null !== $id && '' !== $id && QueueServices::queue()->retryFailed($id)) {
            CacheInvalidator::flush();
            AuditServices::log('queue.retry', 'job:' . $id, 'Re-queued failed job ' . $id, $request->getClientIp());
        }

        return Response::redirect($this->url('mark.queue') . '#failed');
    }

    public function deleteFailed(Request $request): Response
    {
        $id = $request->routeParam('id');

        if (null !== $id && '' !== $id && QueueServices::queue()->deleteFailed($id)) {
            CacheInvalidator::flush();
            AuditServices::log('queue.failed.delete', 'job:' . $id, 'Deleted failed job ' . $id, $request->getClientIp());
        }

        return Response::redirect($this->url('mark.queue') . '#failed');
    }

    private function listPage(Request $request): string
    {
        $filter = $request->query('queue', null);
        $filter = is_string($filter) && '' !== $filter ? $filter : null;

        $jobs = QueueServices::queue()->listJobs($filter);
        $failed = QueueServices::queue()->listFailed();
        $csrf = AdminLayout::csrfField();

        $summary = '<p class="m-muted">' . count($jobs) . ' jobs'
            . (null !== $filter ? ' in queue “' . AdminLayout::e($filter) . '”' : '')
            . ' · ' . count($failed) . ' failed</p>';

        $content = $summary . $this->jobsTable($jobs, $csrf) . $this->failedTable($failed, $csrf);

        return AdminLayout::render('Queue', $content, 'queue');
    }

    /**
     * @param list<array<string, mixed>> $jobs
     */
    private function jobsTable(array $jobs, string $csrf): string
    {
        if ([] === $jobs) {
            return '<p class="m-muted">No jobs queued.</p>';
        }

        $rows = '';

        foreach ($jobs as $job) {
            $id = (string) $job['id'];
            $short = AdminLayout::e(substr($id, 0, 8));
            $attempts = (int) $job['attempts'] . '/' . (int) $job['max_attempts'];

            $rows .= '<tr>'
                . '<td><a href="/mark/queue/' . $id . '" class="m-mono">' . $short . '</a></td>'
                . '<td>' . AdminLayout::e((string) $job['queue']) . '</td>'
                . '<td class="m-mono">' . AdminLayout::e((string) $job['handler']) . '</td>'
                . '<td>' . AdminLayout::e((string) $job['state']) . '</td>'
                . '<td class="m-mono">' . AdminLayout::e($attempts) . '</td>'
                . '<td>' . $this->deleteForm('/mark/queue/' . $id . '/delete', $csrf, 'Drop') . '</td>'
                . '</tr>';
        }

        return '<h2 class="m-heading">Jobs</h2>'
            . '<table class="m-table"><thead><tr>'
            . '<th>Id</th><th>Queue</th><th>Handler</th><th>State</th><th>Attempts</th><th></th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    /**
     * @param list<array<string, mixed>> $failed
     */
    private function failedTable(array $failed, string $csrf): string
    {
        $out = '<h2 class="m-heading" id="failed">Failed jobs</h2>';

        if ([] === $failed) {
            return $out . '<p class="m-muted">No failed jobs.</p>';
        }

        $rows = '';

        foreach ($failed as $job) {
            $id = (string) $job['id'];
            $error = AdminLayout::e($this->excerpt((string) ($job['error'] ?? ''), 100));

            $rows .= '<tr>'
                . '<td class="m-mono">' . AdminLayout::e(substr($id, 0, 8)) . '</td>'
                . '<td>' . AdminLayout::e((string) $job['queue']) . '</td>'
                . '<td class="m-mono">' . AdminLayout::e((string) $job['handler']) . '</td>'
                . '<td>' . $error . '</td>'
                . '<td><div class="m-btn-group">'
                . $this->retryForm('/mark/queue/failed/' . $id . '/retry', $csrf)
                . $this->deleteForm('/mark/queue/failed/' . $id . '/delete', $csrf, 'Delete')
                . '</div></td>'
                . '</tr>';
        }

        return $out
            . '<table class="m-table"><thead><tr>'
            . '<th>Id</th><th>Queue</th><th>Handler</th><th>Error</th><th></th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    /**
     * @param array<string, mixed> $job
     */
    private function detailPage(array $job): string
    {
        $id = (string) $job['id'];
        $csrf = AdminLayout::csrfField();

        $rows = '';
        foreach (['queue' => 'Queue', 'handler' => 'Handler', 'state' => 'State', 'available_at' => 'Available at', 'reserved_at' => 'Reserved at', 'created_at' => 'Created'] as $key => $label) {
            $value = $job[$key] ?? null;
            $rows .= '<tr><td>' . $label . '</td><td class="m-mono">'
                . AdminLayout::e(null !== $value ? (string) $value : '—') . '</td></tr>';
        }

        $rows .= '<tr><td>Attempts</td><td class="m-mono">'
            . (int) $job['attempts'] . '/' . (int) $job['max_attempts'] . '</td></tr>';

        $content = '<table class="m-table"><tbody>' . $rows . '</tbody></table>'
            . '<h2 class="m-heading">Args (payload)</h2>'
            . '<pre class="m-mono" style="overflow:auto">' . AdminLayout::e($this->prettyJson((string) $job['payload'])) . '</pre>'
            . '<p>' . $this->deleteForm('/mark/queue/' . $id . '/delete', $csrf, 'Drop this job') . '</p>'
            . '<p><a href="/mark/queue">← Back to jobs</a></p>';

        return AdminLayout::render('Job ' . substr($id, 0, 8), $content, 'queue');
    }

    /**
     * Retry form: re-queueing is reversible (the job just runs again),
     * so no confirm prompt — small ghost button, QuestionsAdminAction
     * `m-btn-group` shape.
     */
    private function retryForm(string $action, string $csrf): string
    {
        return '<form method="POST" action="' . AdminLayout::e($action) . '"'
            . ' class="m-inline-form">'
            . $csrf
            . '<button class="m-btn m-btn-sm m-btn-ghost">Retry</button></form>';
    }

    /**
     * Icon delete form (QuestionsAdminAction shape): inline form with a
     * confirm prompt — drops are irreversible.
     */
    private function deleteForm(string $action, string $csrf, string $label): string
    {
        return '<form method="POST" action="' . AdminLayout::e($action) . '"'
            . ' class="m-inline-form" data-confirm="' . AdminLayout::e($label . '? This cannot be undone.') . '">'
            . $csrf
            . '<button class="m-btn m-btn-sm m-btn-icon m-btn-error"'
            . ' data-tooltip="' . AdminLayout::e($label) . '" title="' . AdminLayout::e($label) . '"'
            . ' aria-label="' . AdminLayout::e($label) . '"'
            . '>' . AdminLayout::iconDelete() . '</button></form>';
    }

    private function prettyJson(string $payload): string
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

            $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return is_string($pretty) ? $pretty : $payload;
        } catch (\Throwable) {
            // Poison row: show the raw payload instead of fataling.
            return $payload;
        }
    }

    private function excerpt(string $text, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length - 1) . '…';
    }
}
