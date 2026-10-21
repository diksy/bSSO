<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Repository\AuditLogRepository;
use App\Repository\Record\AuditLog;
use App\Repository\UserRepository;
use App\Support\Audit;
use App\Support\Clock;
use App\Support\Csv;

final class AuditLogController
{
    private const PER_PAGE = 25;
    private const EXPORT_MAX = 10000;
    private const FILTERS = ['actor', 'target', 'event', 'from', 'to'];

    /** @var array<int,string> */
    private array $names = [];

    public function __construct(
        private readonly AdminPage $page,
        private readonly AuditLogRepository $logs,
        private readonly UserRepository $users,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {
    }

    public function index(Request $request): Response
    {
        $f = $this->filter($request);
        $total = $this->logs->countFiltered($f);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($pages, (int) ($request->input('page') ?? '1')));
        $rows = [];
        foreach ($this->logs->listFiltered($f, self::PER_PAGE, ($page - 1) * self::PER_PAGE) as $e) {
            $rows[] = [
                'time' => $e->createdAt->format('Y-m-d H:i:s'),
                'actor' => $this->who($e->userId, 'sistem'),
                'event' => $e->event,
                'target' => $this->who($e->targetUserId, '–'),
                'context' => $e->context === null ? null : Audit::scrub($e->context),
            ];
        }

        return $this->page->render($request, 'admin/audit', 'Audit log', 'audit', [
            'f' => $f + ['actor' => '', 'target' => '', 'event' => '', 'from' => '', 'to' => ''],
            'qs' => http_build_query(array_filter($f, static fn (string $v): bool => $v !== '')),
            'total' => $total, 'page' => $page, 'pages' => $pages, 'rows' => $rows,
        ]);
    }

    /** Ekspor CSV (modul audit: manage). Tanpa rahasia; sel tahan formula injection. */
    public function export(Request $request): Response
    {
        $f = $this->filter($request);
        $rows = [['id', 'waktu_utc', 'pelaku_id', 'pelaku', 'event', 'target_id', 'target', 'client_id', 'ip', 'konteks']];
        $offset = 0;
        while ($offset < self::EXPORT_MAX) {
            $batch = $this->logs->listFiltered($f, 1000, $offset);
            foreach ($batch as $e) {
                $rows[] = $this->csvRow($e);
            }
            if (count($batch) < 1000) {
                break;
            }
            $offset += 1000;
        }
        $actor = $this->page->actor($request);
        $this->audit->record('audit.export', $actor->id, null, null, ['filter' => $f, 'rows' => count($rows) - 1]);

        return (new Response(200, Csv::encode($rows)))
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="audit-' . $this->clock->now()->format('Ymd-His') . '.csv"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @return list<string|int|null> */
    private function csvRow(AuditLog $e): array
    {
        $ctx = $e->context === null ? '' : json_encode(Audit::scrub($e->context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            $e->id, $e->createdAt->format('Y-m-d H:i:s'), $e->userId, $this->who($e->userId, ''), $e->event,
            $e->targetUserId, $this->who($e->targetUserId, ''), $e->clientId, $e->ipAddress, $ctx === false ? '' : $ctx,
        ];
    }

    /** @return array<string,string> */
    private function filter(Request $r): array
    {
        $f = [];
        foreach (self::FILTERS as $key) {
            $v = trim($r->input($key) ?? '');
            if (in_array($key, ['from', 'to'], true) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
                $v = '';
            }
            $f[$key] = mb_substr($v, 0, 100);
        }

        return $f;
    }

    private function who(?int $id, string $none): string
    {
        if ($id === null) {
            return $none;
        }

        return $this->names[$id] ??= ($this->users->findById($id)->email ?? '#' . $id);
    }
}
