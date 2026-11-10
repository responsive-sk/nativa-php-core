<?php

declare(strict_types = 1);

namespace Modules\Questions\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\PdoConnection;
use Modules\Questions\Domain\Entities\Question;
use Modules\Questions\Infrastructure\Persistence\Migrations\QuestionsDatabaseMigrator;
use Modules\Questions\Infrastructure\Persistence\Repositories\QuestionRepository;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * QuestionRepositoryContractTest — the repository, driven through the wrapper.
 *
 * Every other Questions test stops at validation: the entity and the action
 * are covered, the SQL is not. That is the gap the move to the connection
 * contract could hide in, because the wrapper is the layer that rewrites
 * `execute([])` and `bindValue()` on the way to PDO (see the `/mark/audit`
 * datatype mismatch). Running the repository's own queries through
 * `PdoConnection` is what makes a silent change to that rewriting a failure
 * here instead of a 500 on a reader's page.
 *
 * Drives the INSTALLED copy, skips on a kernel-only tree.
 */
final class QuestionRepositoryContractTest extends TestCase
{
    private string $dbFile;

    private PDO $pdo;

    private QuestionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Questions')) {
            $this->markTestSkipped('Questions is not installed.');
        }

        $this->dbFile = sys_get_temp_dir() . '/questions-'.bin2hex(random_bytes(8)).'.sqlite';
        $this->pdo = new PDO('sqlite:'.$this->dbFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $null = new class () implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };
        (new QuestionsDatabaseMigrator($this->pdo))->run($null);

        $this->repository = new QuestionRepository(new PdoConnection($this->pdo, 'questions'));
    }

    protected function tearDown(): void
    {
        unset($this->pdo, $this->repository);

        if (isset($this->dbFile) && is_file($this->dbFile)) {
            unlink($this->dbFile);
        }

        parent::tearDown();
    }

    #[Test]
    public function aQuestionWrittenThroughTheWrapperReadsBackOnItsSubject(): void
    {
        $this->repository->insert($this->question(Question::SUBJECT_ARTICLE, 'herdr', 'sk_SK', 'why?'));

        $found = $this->repository->findBySubject(Question::SUBJECT_ARTICLE, 'herdr', 'sk_SK');

        self::assertCount(1, $found, 'the insert has to survive the wrapper to be found');
        self::assertSame('why?', $found[0]->text());
        self::assertSame(Question::STATUS_PENDING, $found[0]->status());
        self::assertSame([], $this->repository->findBySubject(Question::SUBJECT_PAGE, 'herdr', 'sk_SK'), 'a page with the same slug is a different subject');
        self::assertSame([], $this->repository->findBySubject(Question::SUBJECT_ARTICLE, 'herdr', 'en_US'), 'a locale is part of the subject key');
    }

    #[Test]
    public function thePendingCountAndTheInboxOrderFollowStatus(): void
    {
        $first = $this->question(Question::SUBJECT_ARTICLE, 'one', 'sk_SK', 'first');
        $this->repository->insert($first);
        $this->repository->insert($this->question(Question::SUBJECT_ARTICLE, 'two', 'sk_SK', 'second'));

        self::assertSame(2, $this->repository->countPending(), 'countPending() is the badge on every admin page');

        $this->repository->setStatus($first->id(), Question::STATUS_APPROVED);

        self::assertSame(1, $this->repository->countPending());

        // The two rows share a `created_at` second, so only the status grouping
        // is asserted: pending first, approved after.
        self::assertSame(Question::STATUS_PENDING, $this->repository->all()[0]->status(), 'the inbox puts pending rows first');
        self::assertSame(Question::STATUS_APPROVED, $this->repository->all()[1]->status());

        $this->repository->delete($first->id());

        self::assertNull($this->repository->findById($first->id()));
        self::assertCount(1, $this->repository->findPending(), 'deleting the approved row leaves the other pending question alone');
    }

    private function question(string $type, string $slug, string $locale, string $text): Question
    {
        return Question::create($type, $slug, $locale, $text, 'an answer', 'Reader');
    }
}
