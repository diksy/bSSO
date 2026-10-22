<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Repository\SigningKeyRepository;
use App\Security\KeyManager;
use App\Security\RotationResult;
use App\Support\Audit;
use App\Support\Flash;

/** Kunci penandatangan (F8): daftar + rotasi paksa/darurat. Semua POST lewat keys:manage + step-up (routes.php). */
final class KeysController
{
    public function __construct(
        private readonly AdminPage $page,
        private readonly SigningKeyRepository $keys,
        private readonly KeyManager $manager,
        private readonly Audit $audit,
    ) {
    }

    public function index(Request $request): Response
    {
        $fmt = static fn (?\DateTimeImmutable $t): string => $t === null ? '-' : $t->format('Y-m-d H:i');
        $rows = [];
        foreach ($this->keys->listByStatus('active', 'next', 'retiring', 'retired') as $k) {
            $rows[] = [
                'kid' => $k->kid, 'status' => $k->status, 'created' => $fmt($k->createdAt), 'activate' => $fmt($k->activatedAt),
                'retire' => $fmt($k->retiredAt ?? $k->retiringAt),
            ];
        }

        return $this->page->render($request, 'admin/keys', 'Kunci penandatangan', 'keys', ['keys' => $rows]);
    }

    public function rotate(Request $request): Response
    {
        $actor = $this->page->actor($request);
        $before = $this->state();
        $result = $this->manager->rotate(false);
        $this->audit->record('key.rotate', $actor->id, null, null, ['before' => $before, 'after' => $this->after($result)]);
        Flash::add('ok', 'Rotasi dijalankan: ' . implode(', ', $result->actions));

        return Response::redirect('/admin/keys', 303);
    }

    public function rotateEmergency(Request $request): Response
    {
        $actor = $this->page->actor($request);
        if ($request->input('confirm') !== 'ROTATE') {
            Flash::add('err', 'Ketik ROTATE (huruf besar) untuk rotasi darurat.');

            return Response::redirect('/admin/keys', 303);
        }
        $before = $this->state();
        $this->manager->rotate(true, $actor->id, ['before' => $before]);
        Flash::add('ok', 'Rotasi darurat selesai; token lama tidak lagi diterima.');

        return Response::redirect('/admin/keys', 303);
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        $out = [];
        foreach ($this->keys->listByStatus('active', 'next', 'retiring') as $k) {
            $out[$k->kid] = $k->status;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function after(RotationResult $r): array
    {
        return ['actions' => $r->actions, 'active_kid' => $r->activeKid, 'next_kid' => $r->nextKid];
    }
}
