<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Repository\AdminRoleRepository;
use App\Repository\AuditLogRepository;
use App\Repository\LoginAttemptRepository;
use App\Repository\SsoSessionRepository;
use App\Repository\UserRepository;
use App\Security\BackupCodes;
use App\Security\Crypto;
use App\Security\PasswordHasher;
use App\Security\RateLimiter;
use App\Security\SignedTokens;
use App\Security\Totp;
use App\Session\LocalSessionTerminator;
use App\Session\SsoSessionManager;
use App\Support\Audit;
use App\Support\Database;
use App\Support\FrozenClock;
use App\Support\Logger;
use App\Support\Urls;
use App\Support\View;

/** Rangkaian objek auth di atas SQLite in-memory (tanpa container). */
final class Stack
{
    public Database $db;
    public FrozenClock $clock;
    public UserRepository $users;
    public SsoSessionRepository $sessions;
    public LoginAttemptRepository $attempts;
    public AdminRoleRepository $roles;
    public AuditLogRepository $auditLogs;
    public PasswordHasher $hasher;
    public Crypto $crypto;
    public Totp $totp;
    public BackupCodes $backup;
    public RateLimiter $limiter;
    public SsoSessionManager $manager;
    public LocalSessionTerminator $terminator;
    public SignedTokens $tokens;
    public Audit $audit;
    public View $view;
    public Urls $urls;
    public Logger $logger;
    public RecordingMailer $mailer;

    public function __construct()
    {
        $this->db = TestDb::create();
        $this->clock = new FrozenClock('2026-03-01 10:00:00');
        $this->users = new UserRepository($this->db);
        $this->sessions = new SsoSessionRepository($this->db);
        $this->attempts = new LoginAttemptRepository($this->db);
        $this->roles = new AdminRoleRepository($this->db);
        $this->auditLogs = new AuditLogRepository($this->db);
        $this->hasher = new PasswordHasher();
        $this->crypto = new Crypto(
            ['current' => 'v1', 'keys' => ['v1' => base64_encode(str_repeat('a', 32)), 'v0' => base64_encode(str_repeat('b', 32))]],
            str_repeat('h', 32),
        );
        $this->totp = new Totp($this->clock);
        $this->backup = new BackupCodes($this->crypto, $this->users, $this->db);
        $this->limiter = new RateLimiter($this->attempts, $this->clock);
        $this->manager = new SsoSessionManager($this->sessions, $this->clock);
        $this->terminator = new LocalSessionTerminator($this->sessions, $this->clock);
        $this->tokens = new SignedTokens(str_repeat('k', 32), $this->clock);
        $this->audit = new Audit($this->auditLogs, $this->clock, '203.0.113.9');
        $this->view = new View(dirname(__DIR__, 2) . '/views');
        $this->urls = new Urls('https://sso.example.test');
        $this->logger = new Logger(sys_get_temp_dir() . '/bsso-test-logs');
        $this->mailer = new RecordingMailer();
    }
}
