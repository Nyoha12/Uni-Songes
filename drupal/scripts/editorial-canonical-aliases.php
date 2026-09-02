<?php

declare(strict_types=1);

/**
 * @file
 * Fail-closed Article and Forum Topic alias audit/generator for 2026.
 */

use Composer\InstalledVersions;
use Drupal\Component\Render\HtmlEscapedText;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Database\Transaction\ClientConnectionTransactionState;
use Drupal\Core\Database\Transaction\TransactionManagerBase;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\KeyValueStore\DatabaseStorage;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Lock\PersistentDatabaseLockBackend;
use Drupal\Core\Menu\MenuLinkManager;
use Drupal\Core\Menu\MenuTreeStorage;
use Drupal\Core\Routing\RouteProvider;
use Drupal\Core\Transliteration\PhpTransliteration;
use Drupal\mysql\Driver\Database\mysql\TransactionManager as MysqlTransactionManager;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManager;
use Drupal\path_alias\AliasRepository;
use Drupal\path_alias\PathAliasStorage;
use Drupal\pathauto\AliasCleaner;
use Drupal\pathauto\AliasStorageHelper;
use Drupal\pathauto\AliasUniquifier;
use Drupal\pathauto\PathautoGeneratorInterface;
use Drupal\pathauto\PathautoPatternInterface;
use Drupal\pathauto\PathautoState;
use Drupal\pathauto\VerboseMessenger;
use Drupal\redirect\RedirectRepository;
use Drupal\simple_sitemap\Manager\EntityManager as SimpleSitemapEntityManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

const EDITORIAL_ALIAS_SITE_UUID = 'ff0af3b7-b9cf-4d63-8932-4f55870ce430';
const EDITORIAL_ALIAS_LOCK = 'unisonges.editorial_alias_policy.2026';
const EDITORIAL_ALIAS_MAX_LENGTH = 100;
const EDITORIAL_ALIAS_STORAGE_MAX_LENGTH = 255;
const EDITORIAL_ALIAS_LOCK_TTL_SECONDS = 3600.0;
const EDITORIAL_ALIAS_WRITE_WINDOW_SECONDS = 900;
const EDITORIAL_ALIAS_REVIEWED_PACKAGE_TREES = [
  'drupal/core' => [
    'relative_path' => 'drupal/web/core',
    'file_count' => 20318,
    'sha256' => 'cd802d104364329a1e40915039b6cfcd4691d9545422797888a8a9e41984e9a2',
  ],
  'drupal/pathauto' => [
    'relative_path' => 'drupal/web/modules/contrib/pathauto',
    'file_count' => 81,
    'sha256' => '5f46cc5b33de03167081f18696d4fee6c0c8d5cc00667fb645187f38f744f5e7',
  ],
  'drupal/redirect' => [
    'relative_path' => 'drupal/web/modules/contrib/redirect',
    'file_count' => 117,
    'sha256' => '8db2ac89bdce2c42a9b1caee8a7db83b0ad6b5aba9a3acc7eca0abec45f27878',
  ],
  'drupal/simple_sitemap' => [
    'relative_path' => 'drupal/web/modules/contrib/simple_sitemap',
    'file_count' => 153,
    'sha256' => 'f489fb5995788a9e3f3fae7dece2b512a3aca36d7171052fee65d0ba3bc0d3ba',
  ],
  'drupal/token' => [
    'relative_path' => 'drupal/web/modules/contrib/token',
    'file_count' => 93,
    'sha256' => '08ac99c195470c83e568775cbbf9432e1c625a531358386fca4c41a09add1f12',
  ],
];
const EDITORIAL_ALIAS_PUNCTUATION_VALUES = [
  'double_quotes' => '"',
  'quotes' => "'",
  'backtick' => '`',
  'comma' => ',',
  'period' => '.',
  'hyphen' => '-',
  'underscore' => '_',
  'colon' => ':',
  'semicolon' => ';',
  'pipe' => '|',
  'left_curly' => '{',
  'left_square' => '[',
  'right_curly' => '}',
  'right_square' => ']',
  'plus' => '+',
  'equal' => '=',
  'asterisk' => '*',
  'ampersand' => '&',
  'percent' => '%',
  'caret' => '^',
  'dollar' => '$',
  'hash' => '#',
  'at' => '@',
  'exclamation' => '!',
  'tilde' => '~',
  'left_parenthesis' => '(',
  'right_parenthesis' => ')',
  'question_mark' => '?',
  'less_than' => '<',
  'greater_than' => '>',
  'slash' => '/',
  'back_slash' => '\\',
];
const EDITORIAL_ALIAS_TARGETS = [
  'article' => [
    'config_name' => 'pathauto.pattern.article',
    'pattern_id' => 'article',
    'pattern' => 'blog/article/[node:title]',
    'prefix' => '/blog/',
    'hub' => '/blog',
    'label' => 'Articles du Blog',
  ],
  'forum_topic' => [
    'config_name' => 'pathauto.pattern.forum_topic',
    'pattern_id' => 'forum_topic',
    'pattern' => 'forum/topic/[node:title]',
    'prefix' => '/forum/',
    'hub' => '/forum',
    'label' => 'Sujets du Forum',
  ],
];

/**
 * Marks reviewed, privacy-safe refusal messages.
 */
final class EditorialAliasPolicyException extends RuntimeException {
}

/**
 * Rolls back an armed transaction before Core's commit-on-shutdown callback.
 */
final class EditorialAliasShutdownGuard {

  private ?Connection $connection = NULL;

  private ?Transaction $transaction = NULL;

  private string $emergencyMemory;

  private bool $databaseCommitCompleted;

  private bool $databaseCommitAttempted;

  private bool $outcomeUnknown = FALSE;

  private bool $shutdownMayReturn = FALSE;

  public function __construct(
    bool &$database_commit_completed,
    bool &$database_commit_attempted,
  ) {
    $this->databaseCommitCompleted = &$database_commit_completed;
    $this->databaseCommitAttempted = &$database_commit_attempted;
    // Leave enough memory for rollback when shutdown follows memory exhaustion.
    $this->emergencyMemory = str_repeat('R', 1024 * 1024);
  }

  /**
   * Return the exact reviewed client used for emergency full rollback.
   */
  private function reviewedClient(Connection $connection): \PDO {
    $client = $connection->getClientConnection();
    $options = $connection->getConnectionOptions();
    if (get_class($connection) !== \Drupal\mysql\Driver\Database\mysql\Connection::class
      || $connection->driver() !== 'mysql'
      || !$client instanceof \PDO
      || $client->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql'
      || ($options['pdo'][\PDO::ATTR_PERSISTENT] ?? FALSE) !== FALSE
      || $client->getAttribute(\PDO::ATTR_PERSISTENT) !== FALSE) {
      editorial_alias_fail('The fatal-shutdown guard requires the exact reviewed PDO MySQL driver.');
    }
    return $client;
  }

  /**
   * Return the exact reviewed transaction manager with inspectable depth.
   */
  private function reviewedManager(Connection $connection): MysqlTransactionManager {
    $manager = $connection->transactionManager();
    if (get_class($manager) !== MysqlTransactionManager::class) {
      editorial_alias_fail('The fatal-shutdown guard requires the exact reviewed MySQL transaction manager.');
    }
    $manager_connection = (function (): mixed {
      return $this->connection;
    })->call($manager);
    if ($manager_connection !== $connection) {
      editorial_alias_fail('The fatal-shutdown guard requires the exact reviewed MySQL transaction manager.');
    }
    return $manager;
  }

  /**
   * Read Core's exact client-transaction state under the reviewed class pin.
   */
  private function reviewedManagerState(
    MysqlTransactionManager $manager,
  ): ClientConnectionTransactionState {
    $state = (function (): mixed {
      return $this->getConnectionTransactionState();
    })->call($manager);
    if (!$state instanceof ClientConnectionTransactionState) {
      editorial_alias_fail('The reviewed MySQL transaction state is unavailable.');
    }
    return $state;
  }

  /**
   * Reflect a proven direct PDO rollback into Core's callback state.
   */
  private function markManagerRolledBack(
    MysqlTransactionManager $manager,
  ): void {
    (function (): void {
      $this->setConnectionTransactionState(
        ClientConnectionTransactionState::RolledBack,
      );
    })->call($manager);
    if ($this->reviewedManagerState($manager) !== ClientConnectionTransactionState::RolledBack) {
      editorial_alias_fail('Core did not retain the proven client rollback state.');
    }
  }

  /**
   * Read the pinned Core manager lifecycle after Transaction destruction.
   */
  private function reviewedManagerLifecycle(
    MysqlTransactionManager $manager,
  ): array {
    $reader = \Closure::bind(
      function (): array {
        return [
          'root_id' => $this->rootId,
          'stack' => $this->stack,
          'voided_items' => $this->voidedItems,
          'post_transaction_callbacks' => $this->postTransactionCallbacks,
          'state' => $this->connectionTransactionState,
        ];
      },
      $manager,
      TransactionManagerBase::class,
    );
    if (!$reader instanceof \Closure) {
      editorial_alias_fail('The reviewed MySQL transaction lifecycle is unavailable.');
    }
    return $reader();
  }

  /**
   * Start a root transaction with the connection guarded before client begin.
   */
  public function startRootTransaction(Connection $connection, string $name): Transaction {
    editorial_alias_assert_shutdown_guard_first($this);
    if ($this->connection instanceof Connection
      || $this->transaction instanceof Transaction) {
      editorial_alias_fail('The fatal-shutdown transaction guard is already armed.');
    }
    if ($this->emergencyMemory === '') {
      // Normal plan rollbacks also release the reserve; replenish it before
      // the next BEGIN so every separately armed transaction has 1 MiB free.
      $this->emergencyMemory = str_repeat('R', 1024 * 1024);
    }
    $client = $this->reviewedClient($connection);
    $manager = $this->reviewedManager($connection);
    if ($connection->inTransaction()
      || $client->inTransaction()
      || $manager->stackDepth() !== 0) {
      editorial_alias_fail('The fatal-shutdown guard requires no ambient transaction.');
    }

    // Retain the connection before startTransaction(): even a failure between
    // the client BEGIN and creation of Drupal's Transaction object is guarded.
    $this->connection = $connection;
    try {
      $transaction = $connection->startTransaction($name);
      $this->transaction = $transaction;
      if (!$connection->inTransaction()
        || !$client->inTransaction()
        || !$manager->has($transaction->name())
        || $manager->stackDepth() !== 1) {
        editorial_alias_fail('The guarded root transaction did not start exactly.');
      }
      return $transaction;
    }
    catch (Throwable $throwable) {
      try {
        $this->rollbackIfArmed();
      }
      catch (Throwable $rollback_error) {
        throw new EditorialAliasPolicyException(
          'Root transaction start failed and cleanup could not be verified.',
          0,
          $rollback_error,
        );
      }
      throw $throwable;
    }
  }

  /**
   * Commit the exact armed root and make the outcome flag adjacent to commit.
   */
  public function commitRootTransaction(
    ?Transaction &$transaction,
    int $write_deadline_ns,
  ): void {
    if ($this->databaseCommitCompleted
      || $this->databaseCommitAttempted
      || $this->outcomeUnknown
      || !$this->connection instanceof Connection
      || !$this->transaction instanceof Transaction
      || $this->transaction !== $transaction) {
      editorial_alias_fail('The guarded root transaction is not eligible to commit.');
    }
    editorial_alias_assert_shutdown_guard_first($this);
    $client = $this->reviewedClient($this->connection);
    $manager = $this->reviewedManager($this->connection);
    if (!$client->inTransaction()
      || !$manager->inTransaction()
      || !$manager->has($transaction->name())
      || $manager->stackDepth() !== 1) {
      editorial_alias_fail('The guarded root transaction changed before commit.');
    }
    $persistent_lock = \Drupal::service('drupal.proxy_original_service.lock.persistent');
    $feature_lock = \Drupal::service('drupal.proxy_original_service.lock');
    if (!$persistent_lock instanceof PersistentDatabaseLockBackend
      || get_class($persistent_lock) !== PersistentDatabaseLockBackend::class
      || !$feature_lock instanceof DatabaseLockBackend
      || get_class($feature_lock) !== DatabaseLockBackend::class) {
      editorial_alias_fail('The exact reviewed lock backends changed before commit.');
    }
    editorial_alias_assert_lock_owned(
      $persistent_lock,
      ConfigImporter::LOCK_NAME,
      TRUE,
    );
    editorial_alias_assert_lock_owned($feature_lock, EDITORIAL_ALIAS_LOCK, TRUE);
    editorial_alias_assert_write_deadline($write_deadline_ns);

    // A termination after the client commit but before the next assignment is
    // conservatively reported as an unknown outcome, never as a rollback.
    $this->databaseCommitAttempted = TRUE;
    $transaction->commitOrRelease();
    // Once Core returns, either its explicit commit succeeded or MySQL already
    // ended the client transaction. Mark the durable boundary immediately;
    // every later failure is post-commit and must never enter rollback logic.
    $this->databaseCommitCompleted = TRUE;
    $this->databaseCommitAttempted = FALSE;
    if ($client->inTransaction()
      || $manager->inTransaction()
      || $manager->has($transaction->name())
      || $manager->stackDepth() !== 0
      || $this->reviewedManagerState($manager) !== ClientConnectionTransactionState::Committed) {
      $this->disarm();
      editorial_alias_fail('The root transaction did not reach Core\'s exact committed state.');
    }

    // Transaction::commitOrRelease() leaves the root in Core's voided-items
    // list. Drop both retained references now so its destructor purges the
    // root and executes every post-transaction callback before success output.
    $this->disarm();
    $transaction = NULL;
    $lifecycle = $this->reviewedManagerLifecycle($manager);
    if (!array_key_exists('root_id', $lifecycle)
      || $lifecycle['root_id'] !== NULL
      || ($lifecycle['stack'] ?? NULL) !== []
      || ($lifecycle['voided_items'] ?? NULL) !== []
      || ($lifecycle['post_transaction_callbacks'] ?? NULL) !== []
      || ($lifecycle['state'] ?? NULL) !== ClientConnectionTransactionState::Committed) {
      editorial_alias_fail('Core did not purge the committed transaction lifecycle exactly.');
    }
    editorial_alias_assert_write_deadline($write_deadline_ns);
    $this->releaseEmergencyMemory();
  }

  public function commitWasCompleted(): bool {
    return $this->databaseCommitCompleted;
  }

  /**
   * Destroy a verified rolled-back root before another transaction can start.
   */
  public function finalizeVerifiedRollback(
    Connection $connection,
    ?Transaction &$transaction,
  ): void {
    if (!$transaction instanceof Transaction
      || $this->connection instanceof Connection
      || $this->transaction instanceof Transaction
      || $this->databaseCommitCompleted
      || $this->databaseCommitAttempted
      || $this->outcomeUnknown) {
      editorial_alias_fail('A rolled-back transaction is not eligible for finalization.');
    }
    $client = $this->reviewedClient($connection);
    $manager = $this->reviewedManager($connection);
    $state = $this->reviewedManagerState($manager);
    if ($client->inTransaction()
      || $manager->stackDepth() !== 0
      || $state !== ClientConnectionTransactionState::RolledBack) {
      editorial_alias_fail('The verified rollback changed before finalization.');
    }

    $transaction = NULL;
    $lifecycle = $this->reviewedManagerLifecycle($manager);
    if (!array_key_exists('root_id', $lifecycle)
      || $lifecycle['root_id'] !== NULL
      || ($lifecycle['stack'] ?? NULL) !== []
      || ($lifecycle['voided_items'] ?? NULL) !== []
      || ($lifecycle['post_transaction_callbacks'] ?? NULL) !== []
      || ($lifecycle['state'] ?? NULL) !== $state) {
      editorial_alias_fail('Core did not purge the rolled-back transaction lifecycle exactly.');
    }
  }

  public function disarm(): void {
    $this->transaction = NULL;
    $this->connection = NULL;
  }

  public function releaseEmergencyMemory(): void {
    $this->emergencyMemory = '';
  }

  public function outcomeIsUnknown(): bool {
    return $this->outcomeUnknown;
  }

  public function markOutcomeUnknown(): void {
    $this->outcomeUnknown = TRUE;
  }

  /**
   * Allow the registered shutdown callback to return after verified completion.
   */
  public function allowShutdownReturn(): void {
    if ($this->connection instanceof Connection
      || $this->transaction instanceof Transaction
      || $this->databaseCommitAttempted
      || $this->outcomeUnknown) {
      editorial_alias_fail('The helper cannot mark an unsafe process as complete.');
    }
    editorial_alias_assert_shutdown_guard_first($this);
    $this->shutdownMayReturn = TRUE;
  }

  /**
   * Convert every premature exit, including exit(0), into a failed process.
   */
  public function enforceSafeShutdown(): void {
    if ($this->shutdownMayReturn) {
      return;
    }
    try {
      $this->rollbackIfArmed();
    }
    catch (Throwable) {
      $this->outcomeUnknown = TRUE;
    }
    if ($this->databaseCommitCompleted) {
      fwrite(
        STDERR,
        'POST_COMMIT_ERROR Alias/state transaction committed before premature process termination; verify exact runtime state before any retry.' . PHP_EOL,
      );
    }
    elseif ($this->databaseCommitAttempted || $this->outcomeUnknown) {
      fwrite(
        STDERR,
        'TRANSACTION_OUTCOME_UNKNOWN Premature process termination left an unproven transaction outcome; verify exact runtime state and restore the approved backup before any retry.' . PHP_EOL,
      );
    }
    else {
      fwrite(
        STDERR,
        'REFUSE Helper terminated before verified completion; any armed transaction was rolled back.' . PHP_EOL,
      );
    }
    // This also prevents Core's later commit-all callback from converting an
    // early exit(0) into an apparently successful helper invocation.
    exit(1);
  }

  /**
   * Neutralize a stale Drupal stack without claiming how PDO ended.
   */
  private function refuseInactiveOutcome(
    MysqlTransactionManager $manager,
    ?string $transaction_name,
    string $message,
    ?Throwable $previous = NULL,
  ): never {
    try {
      if ($manager->inTransaction()
        || ($transaction_name !== NULL && $manager->has($transaction_name))
        || $manager->stackDepth() !== 0) {
        $manager->voidClientTransaction();
      }
      // Core treats Voided as success when it later invokes callbacks. The
      // server outcome remains unknown here, but callbacks must conservatively
      // receive failure before the retained root Transaction is destroyed.
      $this->markManagerRolledBack($manager);
    }
    catch (Throwable $throwable) {
      $this->outcomeUnknown = TRUE;
      throw new EditorialAliasPolicyException(
        'An inactive client transaction stack could not be neutralized.',
        0,
        $throwable,
      );
    }
    $this->outcomeUnknown = TRUE;
    $this->disarm();
    throw new EditorialAliasPolicyException($message, 0, $previous);
  }

  public function rollbackIfArmed(): void {
    $this->releaseEmergencyMemory();
    if ($this->databaseCommitCompleted) {
      $this->disarm();
      return;
    }
    if (!$this->connection instanceof Connection) {
      if ($this->outcomeUnknown || $this->databaseCommitAttempted) {
        editorial_alias_fail('The prior database transaction outcome remains unknown.');
      }
      $this->transaction = NULL;
      return;
    }

    $connection = $this->connection;
    $client = $this->reviewedClient($connection);
    $manager = $this->reviewedManager($connection);
    $transaction_name = $this->transaction?->name();
    $manager_tracks_transaction = $manager->inTransaction()
      || ($transaction_name !== NULL && $manager->has($transaction_name))
      || $manager->stackDepth() !== 0;
    if (!$client->inTransaction()) {
      if (!$this->transaction instanceof Transaction
        && !$manager_tracks_transaction
        && !$this->databaseCommitAttempted) {
        // startTransaction() failed before opening a client transaction.
        $this->disarm();
        return;
      }
      // An already inactive client may have rolled back, committed, or been
      // implicitly committed. Never infer which outcome occurred.
      $this->refuseInactiveOutcome(
        $manager,
        $transaction_name,
        'The client transaction ended before guarded rollback began.',
      );
    }

    $drupal_rollback_returned = FALSE;
    $drupal_rollback_error = NULL;
    if ($this->transaction instanceof Transaction) {
      try {
        $this->transaction->rollBack();
        $drupal_rollback_returned = TRUE;
      }
      catch (Throwable $throwable) {
        // A fatal may interrupt an entity save while its savepoint remains on
        // top. Root rollback is then out of order; use the explicit client
        // fallback below only while PDO still proves the transaction active.
        $drupal_rollback_error = $throwable;
      }
    }

    if (!$client->inTransaction()) {
      if ($drupal_rollback_returned
        && !$manager->inTransaction()
        && ($transaction_name === NULL || !$manager->has($transaction_name))
        && $manager->stackDepth() === 0
        && $this->reviewedManagerState($manager) === ClientConnectionTransactionState::RolledBack) {
        $this->databaseCommitAttempted = FALSE;
        $this->disarm();
        return;
      }
      // A thrown/partial Core rollback with an inactive PDO has an ambiguous
      // server outcome. Voiding may prevent destructor commits, but it cannot
      // retroactively prove rollback.
      $this->refuseInactiveOutcome(
        $manager,
        $transaction_name,
        'The client transaction ended during an unverified Drupal rollback.',
        $drupal_rollback_error,
      );
    }

    try {
      if ($client->rollBack() !== TRUE || $client->inTransaction()) {
        throw new RuntimeException('The client transaction remained active after rollback.');
      }
      // Direct client rollback can bypass Drupal's savepoint stack. Void every
      // remaining item so Transaction destructors cannot later commit it.
      if ($manager->inTransaction()
        || ($transaction_name !== NULL && $manager->has($transaction_name))
        || $manager->stackDepth() !== 0) {
        $manager->voidClientTransaction();
      }
      $this->markManagerRolledBack($manager);
    }
    catch (Throwable $throwable) {
      $this->outcomeUnknown = TRUE;
      try {
        // Even if PDO rollback failed, make Core's commit-all callback and all
        // Transaction destructors unable to commit/release the retained stack.
        // The still-open PDO transaction will roll back when the process drops
        // its connection; a later guard invocation may retry explicit rollback.
        if ($manager->stackDepth() !== 0) {
          $manager->voidClientTransaction();
        }
      }
      catch (Throwable) {
        // Preserve the original rollback failure as the actionable cause.
      }
      throw new EditorialAliasPolicyException(
        'The guarded client transaction could not be rolled back exactly.',
        0,
        $throwable,
      );
    }

    if ($client->inTransaction()
      || $manager->inTransaction()
      || ($transaction_name !== NULL && $manager->has($transaction_name))
      || $manager->stackDepth() !== 0) {
      $this->outcomeUnknown = TRUE;
      editorial_alias_fail('The fatal-shutdown transaction guard remained active after rollback.');
    }
    $this->databaseCommitAttempted = FALSE;
    $this->disarm();
  }

}

/**
 * Throw one consistently formatted refusal.
 */
function editorial_alias_fail(string $message): never {
  throw new EditorialAliasPolicyException($message);
}

/**
 * Start and enforce the bounded monotonic alias-write window.
 */
function editorial_alias_write_deadline(): int {
  if (PHP_INT_SIZE !== 8) {
    editorial_alias_fail('The bounded write window requires 64-bit PHP integers.');
  }
  return hrtime(TRUE)
    + (EDITORIAL_ALIAS_WRITE_WINDOW_SECONDS * 1_000_000_000);
}

function editorial_alias_assert_write_deadline(int $deadline_ns): void {
  if ($deadline_ns <= 0 || hrtime(TRUE) >= $deadline_ns) {
    editorial_alias_fail('The bounded alias-write window expired.');
  }
}

/**
 * Verify one exact database-backed lock row and its remaining lease.
 */
function editorial_alias_assert_lock_owned(
  DatabaseLockBackend $backend,
  string $name,
  bool $locking_read = FALSE,
): void {
  $connection = (function (): mixed {
    return $this->database;
  })->call($backend);
  if (!$connection instanceof Connection || $connection !== \Drupal::database()) {
    editorial_alias_fail('A reviewed lock backend lost its guarded connection.');
  }
  try {
    $query = $connection->select('semaphore', 'lock_row')
      ->fields('lock_row', ['name', 'value', 'expire'])
      ->condition('name', $name)
      ->range(0, 2);
    if ($locking_read) {
      $query->forUpdate();
    }
    $rows = $query->execute()->fetchAll();
  }
  catch (Throwable) {
    editorial_alias_fail('A reviewed database lock row could not be inspected.');
  }
  $minimum_expiry = microtime(TRUE) + EDITORIAL_ALIAS_WRITE_WINDOW_SECONDS;
  if (count($rows) !== 1
    || !is_object($rows[0])
    || ($rows[0]->name ?? NULL) !== $name
    || !is_string($rows[0]->value ?? NULL)
    || !hash_equals($backend->getLockId(), $rows[0]->value)
    || !is_numeric($rows[0]->expire ?? NULL)
    || (float) $rows[0]->expire <= $minimum_expiry) {
    editorial_alias_fail('A reviewed database lock is not exclusively owned for the write window.');
  }
}

/**
 * Print one machine-readable status line.
 */
function editorial_alias_line(string $status, string $message): void {
  print $status . ' ' . $message . PHP_EOL;
}

/**
 * Prove the rollback guard remains first in Drupal's callback list.
 */
function editorial_alias_assert_shutdown_guard_first(EditorialAliasShutdownGuard $guard): void {
  if (!function_exists('drupal_register_shutdown_function')) {
    editorial_alias_fail('Drupal shutdown registration is unavailable.');
  }
  $callbacks = &drupal_register_shutdown_function();
  if (($callbacks[0]['callback'] ?? NULL) !== [$guard, 'enforceSafeShutdown']) {
    editorial_alias_fail('The fatal-shutdown rollback guard is not first.');
  }
}

/**
 * Put the rollback guard before Core's transaction commit callback.
 */
function editorial_alias_register_shutdown_guard(EditorialAliasShutdownGuard $guard): void {
  if (!function_exists('drupal_register_shutdown_function')) {
    editorial_alias_fail('Drupal shutdown registration is unavailable.');
  }
  $callback = [$guard, 'enforceSafeShutdown'];
  drupal_register_shutdown_function($callback);
  $callbacks = &drupal_register_shutdown_function();
  $last_key = array_key_last($callbacks);
  if ($last_key === NULL || ($callbacks[$last_key]['callback'] ?? NULL) !== $callback) {
    editorial_alias_fail('The fatal-shutdown rollback guard could not be registered exactly.');
  }
  $entry = $callbacks[$last_key];
  unset($callbacks[$last_key]);
  array_unshift($callbacks, $entry);
  editorial_alias_assert_shutdown_guard_first($guard);
}

/**
 * Recursively sort mapping keys while preserving list order.
 */
function editorial_alias_canonicalize(mixed $value): mixed {
  if (!is_array($value)) {
    return $value;
  }
  foreach ($value as $key => $item) {
    $value[$key] = editorial_alias_canonicalize($item);
  }
  if (!array_is_list($value)) {
    ksort($value, SORT_STRING);
  }
  return $value;
}

/**
 * Hash structured state without depending on mapping insertion order.
 */
function editorial_alias_hash_data(mixed $value): string {
  return hash('sha256', json_encode(
    editorial_alias_canonicalize($value),
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
  ));
}

/**
 * Compare structured state with normalized mapping order.
 */
function editorial_alias_data_equals(mixed $left, mixed $right): bool {
  return editorial_alias_canonicalize($left) === editorial_alias_canonicalize($right);
}

/**
 * Validate and return an exact regular file.
 */
function editorial_alias_exact_file(string $path): string {
  $resolved = realpath($path);
  if ($resolved === FALSE
    || $resolved !== $path
    || !is_file($path)
    || !is_readable($path)
    || is_link($path)) {
    editorial_alias_fail('Exact regular-file guard failed for ' . $path . '.');
  }
  return $resolved;
}

/**
 * Verify complete locked package trees against the statically reviewed dists.
 */
function editorial_alias_reviewed_package_trees(string $repo_root): array {
  $snapshot = [];
  foreach (EDITORIAL_ALIAS_REVIEWED_PACKAGE_TREES as $package => $expected) {
    $expected_root = $repo_root . '/' . $expected['relative_path'];
    $install_path = InstalledVersions::getInstallPath($package);
    $resolved_root = is_string($install_path) ? realpath($install_path) : FALSE;
    if ($resolved_root === FALSE
      || $resolved_root !== $expected_root
      || !is_dir($resolved_root)
      || !is_readable($resolved_root)
      || is_link($resolved_root)) {
      editorial_alias_fail('A reviewed Composer package is not installed at its exact project path.');
    }

    $files = [];
    try {
      $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
          $resolved_root,
          FilesystemIterator::SKIP_DOTS,
        ),
        RecursiveIteratorIterator::LEAVES_ONLY,
      );
      foreach ($iterator as $file_info) {
        if (!$file_info instanceof SplFileInfo
          || $file_info->isLink()
          || !$file_info->isFile()
          || !$file_info->isReadable()) {
          editorial_alias_fail('A reviewed Composer package contains an unsupported filesystem entry.');
        }
        $path = $file_info->getPathname();
        $relative_path = substr($path, strlen($resolved_root) + 1);
        if ($relative_path === ''
          || str_contains($relative_path, "\0")
          || isset($files[$relative_path])) {
          editorial_alias_fail('A reviewed Composer package tree is ambiguous.');
        }
        $files[$relative_path] = $path;
      }
    }
    catch (EditorialAliasPolicyException $exception) {
      throw $exception;
    }
    catch (Throwable) {
      editorial_alias_fail('A reviewed Composer package tree could not be enumerated.');
    }

    ksort($files, SORT_STRING);
    $hash_context = hash_init('sha256');
    foreach ($files as $relative_path => $path) {
      $file_hash = hash_file('sha256', $path, TRUE);
      if (!is_string($file_hash)) {
        editorial_alias_fail('A reviewed Composer package file could not be hashed.');
      }
      hash_update(
        $hash_context,
        pack('N', strlen($relative_path)) . $relative_path . $file_hash,
      );
    }
    $tree_hash = hash_final($hash_context);
    if (count($files) !== $expected['file_count']
      || !hash_equals($expected['sha256'], $tree_hash)) {
      editorial_alias_fail('A locked Core/contrib package differs from its reviewed dist tree.');
    }
    $snapshot[$package] = [
      'file_count' => count($files),
      'sha256' => $tree_hash,
    ];
  }
  ksort($snapshot, SORT_STRING);
  return $snapshot;
}

/**
 * Parse a reviewed YAML mapping.
 */
function editorial_alias_read_yaml(string $path): array {
  editorial_alias_exact_file($path);
  try {
    $data = Yaml::parseFile($path);
  }
  catch (Throwable) {
    editorial_alias_fail('Could not parse reviewed YAML.');
  }
  if (!is_array($data) || array_is_list($data)) {
    editorial_alias_fail('Reviewed YAML must contain a top-level mapping.');
  }
  return $data;
}

/**
 * Ignore only the export provenance hash when comparing config semantics.
 */
function editorial_alias_without_default_hash(array $data): array {
  if (isset($data['_core'])
    && is_array($data['_core'])
    && array_keys($data['_core']) === ['default_config_hash']) {
    unset($data['_core']);
  }
  return $data;
}

/**
 * Read stored active config and reject runtime overrides.
 */
function editorial_alias_active_config(string $name): array {
  $stored = \Drupal::service('config.storage')->read($name);
  if (!is_array($stored)) {
    editorial_alias_fail('Required active configuration is missing: ' . $name . '.');
  }
  $effective = \Drupal::config($name)->get();
  if (!is_array($effective) || !editorial_alias_data_equals($stored, $effective)) {
    editorial_alias_fail('A runtime override affects required configuration: ' . $name . '.');
  }
  return $stored;
}

/**
 * Require one exact SQL entity storage on the guarded default connection.
 */
function editorial_alias_assert_sql_storage(
  mixed $storage,
  string $expected_class,
  Connection $connection,
  array $expected_tables,
  string $expected_base_table,
  ?string $expected_revision_table,
): void {
  if (!$storage instanceof SqlContentEntityStorage
    || get_class($storage) !== $expected_class) {
    editorial_alias_fail('A durable entity storage differs from the reviewed SQL implementation.');
  }
  $storage_connection = (function (): mixed {
    return $this->database;
  })->call($storage);
  $table_names = $storage->getTableMapping()->getTableNames();
  sort($table_names, SORT_STRING);
  sort($expected_tables, SORT_STRING);
  if ($storage_connection !== $connection
    || $storage->getBaseTable() !== $expected_base_table
    || $storage->getRevisionTable() !== $expected_revision_table
    || $storage->getDataTable() !== NULL
    || $storage->getRevisionDataTable() !== NULL
    || $table_names !== $expected_tables) {
    editorial_alias_fail('A durable entity storage connection or table mapping is not exact.');
  }
}

/**
 * Prove direct TRIGGER metadata visibility for one physical MySQL table.
 */
function editorial_alias_direct_trigger_grants(
  Connection $connection,
  string $grantee,
  string $database,
  string $table,
): array {
  $scopes = [
    'global' => [
      'view' => 'information_schema.user_privileges',
      'conditions' => [],
    ],
    'schema' => [
      'view' => 'information_schema.schema_privileges',
      'conditions' => ['table_schema' => $database],
    ],
    'table' => [
      'view' => 'information_schema.table_privileges',
      'conditions' => [
        'table_schema' => $database,
        'table_name' => $table,
      ],
    ],
  ];
  $grants = [];
  try {
    foreach ($scopes as $scope => $definition) {
      $query = $connection->select($definition['view'], 'privilege_info')
        ->fields('privilege_info', ['grantee'])
        ->condition('grantee', $grantee)
        ->condition('privilege_type', 'TRIGGER')
        ->range(0, 2);
      foreach ($definition['conditions'] as $field => $value) {
        $query->condition($field, $value);
      }
      $rows = $query->execute()->fetchCol();
      if (count($rows) > 1
        || (count($rows) === 1 && ($rows[0] ?? NULL) !== $grantee)) {
        editorial_alias_fail('Direct MySQL TRIGGER privilege metadata is ambiguous.');
      }
      $grants[$scope] = count($rows) === 1;
    }
  }
  catch (EditorialAliasPolicyException $exception) {
    throw $exception;
  }
  catch (Throwable) {
    editorial_alias_fail('Direct MySQL TRIGGER privilege metadata could not be inspected.');
  }
  // A global grant alone is insufficient under MySQL partial revokes: its
  // schema restriction is not represented by USER_PRIVILEGES. An explicit
  // schema/table grant is therefore required for this exact object.
  if (($grants['schema'] ?? FALSE) !== TRUE
    && ($grants['table'] ?? FALSE) !== TRUE) {
    editorial_alias_fail('A direct schema/table TRIGGER grant is required to prove complete trigger visibility.');
  }
  return $grants;
}

/**
 * Require the exact reviewed Pathauto/PathAlias/Redirect service graph.
 */
function editorial_alias_assert_alias_services(Connection $connection): array {
  $entity_type_manager = \Drupal::entityTypeManager();
  $alias_repository = \Drupal::service('path_alias.repository');
  if (get_class($alias_repository) !== AliasRepository::class) {
    editorial_alias_fail('The exact reviewed PathAlias repository is not active.');
  }
  $alias_repository_connection = (function (): mixed {
    return $this->connection;
  })->call($alias_repository);
  if ($alias_repository_connection !== $connection) {
    editorial_alias_fail('The PathAlias repository is not on the guarded connection.');
  }

  $alias_manager = \Drupal::service('path_alias.manager');
  if (get_class($alias_manager) !== AliasManager::class) {
    editorial_alias_fail('The exact reviewed PathAlias manager is not active.');
  }
  $manager_repository = (function (): mixed {
    return $this->pathAliasRepository;
  })->call($alias_manager);
  if ($manager_repository !== $alias_repository) {
    editorial_alias_fail('The PathAlias manager does not use the reviewed repository.');
  }

  $storage_helper = \Drupal::service('pathauto.alias_storage_helper');
  if (get_class($storage_helper) !== AliasStorageHelper::class) {
    editorial_alias_fail('The exact reviewed Pathauto storage helper is not active.');
  }
  $storage_helper_dependencies = (function (): array {
    return [
      'config_factory' => $this->configFactory,
      'alias_repository' => $this->aliasRepository,
      'database' => $this->database,
      'messenger' => $this->messenger,
      'string_translation' => $this->stringTranslation,
      'entity_type_manager' => $this->entityTypeManager,
    ];
  })->call($storage_helper);
  $verbose_messenger = \Drupal::service('pathauto.verbose_messenger');
  $verbose_messenger_dependencies = get_class($verbose_messenger) === VerboseMessenger::class
    ? (function (): array {
      return [
        'config_factory' => $this->configFactory,
        'account' => $this->account,
        'messenger' => $this->messenger,
      ];
    })->call($verbose_messenger)
    : [];
  if (($storage_helper_dependencies['config_factory'] ?? NULL) !== \Drupal::configFactory()
    || ($storage_helper_dependencies['alias_repository'] ?? NULL) !== $alias_repository
    || ($storage_helper_dependencies['database'] ?? NULL) !== $connection
    || ($storage_helper_dependencies['messenger'] ?? NULL) !== $verbose_messenger
    || get_class($verbose_messenger) !== VerboseMessenger::class
    || ($verbose_messenger_dependencies['config_factory'] ?? NULL) !== \Drupal::configFactory()
    || ($verbose_messenger_dependencies['account'] ?? NULL) !== \Drupal::currentUser()
    || ($verbose_messenger_dependencies['messenger'] ?? NULL) !== \Drupal::messenger()
    || ($storage_helper_dependencies['string_translation'] ?? NULL) !== \Drupal::service('string_translation')
    || ($storage_helper_dependencies['entity_type_manager'] ?? NULL) !== $entity_type_manager) {
    editorial_alias_fail('The Pathauto storage helper dependencies are not exact.');
  }

  $alias_cleaner = \Drupal::service('pathauto.alias_cleaner');
  $alias_uniquifier = \Drupal::service('pathauto.alias_uniquifier');
  if (get_class($alias_cleaner) !== AliasCleaner::class
    || get_class($alias_uniquifier) !== AliasUniquifier::class) {
    editorial_alias_fail('The exact reviewed Pathauto cleaner/uniquifier are not active.');
  }
  $cleaner_dependencies = (function (): array {
    return [
      'config_factory' => $this->configFactory,
      'alias_storage_helper' => $this->aliasStorageHelper,
      'language_manager' => $this->languageManager,
      'cache_backend' => $this->cacheBackend,
      'transliteration' => $this->transliteration,
      'module_handler' => $this->moduleHandler,
    ];
  })->call($alias_cleaner);
  $uniquifier_dependencies = (function (): array {
    return [
      'config_factory' => $this->configFactory,
      'alias_manager' => $this->aliasManager,
      'alias_storage_helper' => $this->aliasStorageHelper,
      'module_handler' => $this->moduleHandler,
      'route_provider' => $this->routeProvider,
    ];
  })->call($alias_uniquifier);
  $transliteration = \Drupal::service('transliteration');
  $transliteration_dependencies = get_class($transliteration) === PhpTransliteration::class
    ? (function (): array {
      return [
        'data_directory' => $this->dataDirectory,
        'module_handler' => $this->moduleHandler,
      ];
    })->call($transliteration)
    : [];
  $route_provider = \Drupal::service('router.route_provider');
  $route_provider_dependencies = get_class($route_provider) === RouteProvider::class
    ? (function (): array {
      return [
        'connection' => $this->connection,
        'table' => $this->tableName,
      ];
    })->call($route_provider)
    : [];
  if (($cleaner_dependencies['config_factory'] ?? NULL) !== \Drupal::configFactory()
    || ($cleaner_dependencies['alias_storage_helper'] ?? NULL) !== $storage_helper
    || ($cleaner_dependencies['language_manager'] ?? NULL) !== \Drupal::languageManager()
    || ($cleaner_dependencies['cache_backend'] ?? NULL) !== \Drupal::service('cache.discovery')
    || ($cleaner_dependencies['transliteration'] ?? NULL) !== $transliteration
    || ($cleaner_dependencies['module_handler'] ?? NULL) !== \Drupal::moduleHandler()
    || get_class($transliteration) !== PhpTransliteration::class
    || ($transliteration_dependencies['data_directory'] ?? NULL) !== DRUPAL_ROOT . '/core/lib/Drupal/Component/Transliteration/data'
    || ($transliteration_dependencies['module_handler'] ?? NULL) !== \Drupal::moduleHandler()
    || ($uniquifier_dependencies['config_factory'] ?? NULL) !== \Drupal::configFactory()
    || ($uniquifier_dependencies['alias_manager'] ?? NULL) !== $alias_manager
    || ($uniquifier_dependencies['alias_storage_helper'] ?? NULL) !== $storage_helper
    || ($uniquifier_dependencies['module_handler'] ?? NULL) !== \Drupal::moduleHandler()
    || ($uniquifier_dependencies['route_provider'] ?? NULL) !== $route_provider
    || get_class($route_provider) !== RouteProvider::class
    || ($route_provider_dependencies['connection'] ?? NULL) !== $connection
    || ($route_provider_dependencies['table'] ?? NULL) !== 'router') {
    editorial_alias_fail('The Pathauto cleaner/uniquifier dependencies are not exact.');
  }

  $redirect_repository = \Drupal::service('redirect.repository');
  if (get_class($redirect_repository) !== RedirectRepository::class) {
    editorial_alias_fail('The exact reviewed Redirect repository is not active.');
  }
  $redirect_repository_dependencies = (function (): array {
    return [
      'connection' => $this->connection,
      'entity_type_manager' => $this->manager,
    ];
  })->call($redirect_repository);
  if (($redirect_repository_dependencies['connection'] ?? NULL) !== $connection
    || ($redirect_repository_dependencies['entity_type_manager'] ?? NULL) !== $entity_type_manager) {
    editorial_alias_fail('The Redirect repository dependencies are not exact.');
  }

  return [
    'alias_cleaner' => get_class($alias_cleaner),
    'alias_manager' => get_class($alias_manager),
    'alias_repository' => get_class($alias_repository),
    'alias_storage_helper' => get_class($storage_helper),
    'alias_uniquifier' => get_class($alias_uniquifier),
    'redirect_repository' => get_class($redirect_repository),
    'route_provider' => get_class($route_provider),
    'transliteration' => get_class($transliteration),
    'verbose_messenger' => get_class($verbose_messenger),
  ];
}

/**
 * Refuse a stale or altered persistent Pathauto punctuation cache.
 */
function editorial_alias_assert_punctuation_values(): void {
  $punctuation = \Drupal::service('pathauto.alias_cleaner')
    ->getPunctuationCharacters();
  if (!is_array($punctuation)) {
    editorial_alias_fail('The active Pathauto punctuation inventory is malformed.');
  }
  $values = [];
  foreach ($punctuation as $name => $details) {
    if (!is_string($name)
      || !is_array($details)
      || !isset($details['value'])
      || !is_string($details['value'])) {
      editorial_alias_fail('The active Pathauto punctuation inventory is malformed.');
    }
    $values[$name] = $details['value'];
  }
  if ($values !== EDITORIAL_ALIAS_PUNCTUATION_VALUES) {
    editorial_alias_fail('The active Pathauto punctuation inventory differs from reviewed Pathauto 1.14.');
  }
}

/**
 * Prove every durable table reachable from an alias insert is transactional.
 */
function editorial_alias_transactional_storage_snapshot(
  Connection $connection,
): array {
  if (get_class($connection) !== \Drupal\mysql\Driver\Database\mysql\Connection::class
    || $connection->driver() !== 'mysql') {
    editorial_alias_fail('Transactional storage validation requires the exact reviewed MySQL connection.');
  }
  $alias_services = editorial_alias_assert_alias_services($connection);

  editorial_alias_assert_sql_storage(
    \Drupal::entityTypeManager()->getStorage('path_alias'),
    PathAliasStorage::class,
    $connection,
    ['path_alias', 'path_alias_revision'],
    'path_alias',
    'path_alias_revision',
  );
  editorial_alias_assert_sql_storage(
    \Drupal::entityTypeManager()->getStorage('redirect'),
    SqlContentEntityStorage::class,
    $connection,
    ['redirect'],
    'redirect',
    NULL,
  );

  $state_store = \Drupal::keyValue('pathauto_state.node');
  if (get_class($state_store) !== DatabaseStorage::class
    || $state_store->getCollectionName() !== 'pathauto_state.node') {
    editorial_alias_fail('The reviewed transactional Pathauto state store is not active.');
  }
  $state_store_internals = (function (): array {
    return [
      'connection' => $this->connection,
      'table' => $this->table,
    ];
  })->call($state_store);
  if (($state_store_internals['connection'] ?? NULL) !== $connection
    || ($state_store_internals['table'] ?? NULL) !== 'key_value') {
    editorial_alias_fail('The Pathauto state store is not on the guarded key-value table.');
  }

  $menu_link_manager = \Drupal::service('plugin.manager.menu.link');
  if (get_class($menu_link_manager) !== MenuLinkManager::class) {
    editorial_alias_fail('The reviewed menu-link manager is not active.');
  }
  $menu_tree_storage = (function (): mixed {
    return $this->treeStorage;
  })->call($menu_link_manager);
  if (!$menu_tree_storage instanceof MenuTreeStorage
    || get_class($menu_tree_storage) !== MenuTreeStorage::class) {
    editorial_alias_fail('The reviewed menu-tree storage is not active.');
  }
  $menu_tree_internals = (function (): array {
    return [
      'connection' => $this->connection,
      'table' => $this->table,
    ];
  })->call($menu_tree_storage);
  if (($menu_tree_internals['connection'] ?? NULL) !== $connection
    || ($menu_tree_internals['table'] ?? NULL) !== 'menu_tree') {
    editorial_alias_fail('The menu-tree hook storage is not on the guarded table.');
  }

  // Core 11.3.3 exposes these lazy backends through generated proxies. Inspect
  // and later use the exact original services behind those public proxies.
  $feature_lock = \Drupal::service('drupal.proxy_original_service.lock');
  $persistent_lock = \Drupal::service('drupal.proxy_original_service.lock.persistent');
  if (get_class($feature_lock) !== DatabaseLockBackend::class
    || get_class($persistent_lock) !== PersistentDatabaseLockBackend::class
    || $feature_lock === $persistent_lock) {
    editorial_alias_fail('The exact reviewed database lock backends are not active.');
  }
  foreach ([$feature_lock, $persistent_lock] as $lock_backend) {
    $lock_connection = (function (): mixed {
      return $this->database;
    })->call($lock_backend);
    if ($lock_connection !== $connection) {
      editorial_alias_fail('A reviewed lock backend is not on the guarded connection.');
    }
  }
  $feature_lock_id = $feature_lock->getLockId();
  $persistent_lock_id = $persistent_lock->getLockId();
  if (!is_string($feature_lock_id)
    || $feature_lock_id === ''
    || strlen($feature_lock_id) > 255
    || $feature_lock_id === 'persistent'
    || $persistent_lock_id !== 'persistent') {
    editorial_alias_fail('The reviewed lock backend identities are not exact.');
  }

  $simple_sitemap_manager = \Drupal::service('simple_sitemap.entity_manager');
  if (!$simple_sitemap_manager instanceof SimpleSitemapEntityManager
    || get_class($simple_sitemap_manager) !== SimpleSitemapEntityManager::class) {
    editorial_alias_fail('The reviewed Simple Sitemap entity manager is not active.');
  }
  $simple_sitemap_connection = (function (): mixed {
    return $this->database;
  })->call($simple_sitemap_manager);
  if ($simple_sitemap_connection !== $connection) {
    editorial_alias_fail('Simple Sitemap overrides are not on the guarded connection.');
  }

  $options = $connection->getConnectionOptions();
  $default_database = $options['database'] ?? NULL;
  $prefix = $connection->getPrefix();
  if (!is_string($default_database)
    || $default_database === ''
    || preg_match('/[\x00-\x1f\x7f]/', $default_database)
    || preg_match('/[\x00-\x1f\x7f]/', $prefix)) {
    editorial_alias_fail('The guarded MySQL database or table prefix is invalid.');
  }

  $snapshot = [];
  $current_account = NULL;
  foreach ([
    'key_value',
    'menu_tree',
    'path_alias',
    'path_alias_revision',
    'redirect',
    'semaphore',
    'simple_sitemap_entity_overrides',
  ] as $logical_table) {
    // Reproduce Core MySQL Schema::getPrefixInfo(), including a cross-schema
    // prefix, without interpolating an identifier into a raw SQL statement.
    $qualified_table = $prefix . $logical_table;
    $dot = strpos($qualified_table, '.');
    if ($dot === FALSE) {
      $database = $default_database;
      $table = $qualified_table;
    }
    else {
      $database = substr($qualified_table, 0, $dot);
      $table = substr($qualified_table, $dot + 1);
    }
    if ($database === '' || $table === '') {
      editorial_alias_fail('A guarded durable table has an invalid qualified name.');
    }

    try {
      $rows = $connection->select('information_schema.tables', 'table_info')
        ->fields('table_info', ['table_name', 'table_type', 'engine'])
        ->addExpression('CURRENT_USER()', 'current_account')
        ->condition('table_schema', $database)
        ->condition('table_name', $table)
        ->execute()
        ->fetchAll();
    }
    catch (Throwable) {
      editorial_alias_fail('A guarded durable table engine could not be inspected.');
    }
    if (count($rows) !== 1
      || !is_object($rows[0])
      || ($rows[0]->table_name ?? NULL) !== $table
      || strcasecmp((string) ($rows[0]->table_type ?? ''), 'BASE TABLE') !== 0
      || strcasecmp((string) ($rows[0]->engine ?? ''), 'InnoDB') !== 0
      || !is_string($rows[0]->current_account ?? NULL)) {
      editorial_alias_fail('Every durable alias-operation table must be one exact InnoDB base table.');
    }
    if ($current_account === NULL) {
      $current_account = $rows[0]->current_account;
    }
    elseif (!hash_equals($current_account, $rows[0]->current_account)) {
      editorial_alias_fail('The effective MySQL account changed during storage inspection.');
    }
    if (preg_match('/^([A-Za-z0-9_.%$-]+)@([A-Za-z0-9_.:%$-]+)$/D', $current_account, $account_parts) !== 1) {
      editorial_alias_fail('The effective MySQL account cannot be mapped safely to privilege metadata.');
    }
    $grantee = "'" . $account_parts[1] . "'@'" . $account_parts[2] . "'";
    $trigger_grants = editorial_alias_direct_trigger_grants(
      $connection,
      $grantee,
      $database,
      $table,
    );
    try {
      $trigger_count = (int) $connection
        ->select('information_schema.triggers', 'trigger_info')
        ->condition('event_object_schema', $database)
        ->condition('event_object_table', $table)
        ->countQuery()
        ->execute()
        ->fetchField();
    }
    catch (Throwable) {
      editorial_alias_fail('Guarded-table triggers could not be inspected.');
    }
    if ($trigger_count !== 0) {
      editorial_alias_fail('A guarded durable table has an unaudited database trigger.');
    }
    $snapshot[$logical_table] = [
      'database' => $database,
      'table' => $table,
      'table_type' => 'BASE TABLE',
      'engine' => 'InnoDB',
      'trigger_count' => 0,
      'trigger_grants' => $trigger_grants,
    ];
  }

  return [
    'connection_class' => get_class($connection),
    'alias_services' => $alias_services,
    'storage_classes' => [
      'menu_tree' => get_class($menu_tree_storage),
      'path_alias' => PathAliasStorage::class,
      'pathauto_state' => DatabaseStorage::class,
      'redirect' => SqlContentEntityStorage::class,
      'semaphore' => [
        get_class($feature_lock),
        get_class($persistent_lock),
      ],
      'simple_sitemap_overrides' => SimpleSitemapEntityManager::class,
    ],
    'mysql_account_sha256' => hash('sha256', $current_account),
    'tables' => $snapshot,
  ];
}

/**
 * Validate one config object against the runtime schema.
 */
function editorial_alias_validate_schema(string $name, array $data): void {
  try {
    $typed = \Drupal::service('config.typed')->createFromNameAndData($name, $data);
    $violations = $typed->validate();
  }
  catch (Throwable) {
    editorial_alias_fail('Schema validation could not run for ' . $name . '.');
  }
  if (count($violations) !== 0) {
    editorial_alias_fail('Schema validation failed for ' . $name . '.');
  }
}

/**
 * Require the exact schema-complete source for one bundle pattern.
 */
function editorial_alias_assert_pattern_source(string $bundle, array $source): void {
  $target = EDITORIAL_ALIAS_TARGETS[$bundle];
  $expected_keys = [
    'uuid',
    'langcode',
    'status',
    'dependencies',
    'id',
    'label',
    'type',
    'pattern',
    'selection_criteria',
    'selection_logic',
    'weight',
    'relationships',
  ];
  $actual_keys = array_keys($source);
  sort($expected_keys, SORT_STRING);
  sort($actual_keys, SORT_STRING);
  if ($actual_keys !== $expected_keys
    || !is_string($source['uuid'] ?? NULL)
    || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $source['uuid']) !== 1
    || ($source['langcode'] ?? NULL) !== 'fr'
    || ($source['status'] ?? NULL) !== TRUE
    || ($source['dependencies'] ?? NULL) !== ['module' => ['node']]
    || ($source['id'] ?? NULL) !== $target['pattern_id']
    || ($source['label'] ?? NULL) !== $target['label']
    || ($source['type'] ?? NULL) !== 'canonical_entities:node'
    || ($source['pattern'] ?? NULL) !== $target['pattern']
    || ($source['selection_logic'] ?? NULL) !== 'and'
    || ($source['weight'] ?? NULL) !== -5
    || ($source['relationships'] ?? NULL) !== []) {
    editorial_alias_fail('The reviewed ' . $bundle . ' pattern source has an unexpected shape or value.');
  }

  $criteria = $source['selection_criteria'] ?? NULL;
  if (!is_array($criteria) || count($criteria) !== 1) {
    editorial_alias_fail('The reviewed ' . $bundle . ' pattern must contain exactly one selection criterion.');
  }
  $criterion_uuid = array_key_first($criteria);
  $criterion = $criteria[$criterion_uuid] ?? NULL;
  if (!is_string($criterion_uuid)
    || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $criterion_uuid) !== 1
    || !is_array($criterion)
    || ($criterion['id'] ?? NULL) !== 'entity_bundle:node'
    || ($criterion['negate'] ?? NULL) !== FALSE
    || ($criterion['uuid'] ?? NULL) !== $criterion_uuid
    || ($criterion['context_mapping'] ?? NULL) !== ['node' => 'node']
    || ($criterion['bundles'] ?? NULL) !== [$bundle => $bundle]) {
    editorial_alias_fail('The reviewed ' . $bundle . ' pattern bundle criterion is not exact.');
  }
  editorial_alias_validate_schema($target['config_name'], $source);
}

/**
 * Return hashes for the fixed reviewed source inventory.
 */
function editorial_alias_source_inventory(string $repo_root): array {
  $relative_files = [
    'drupal/composer.lock',
    'drupal/config/sync/core.base_field_override.node.forum_topic.status.yml',
    'drupal/config/sync/core.extension.yml',
    'drupal/config/sync/node.type.article.yml',
    'drupal/config/sync/node.type.forum_topic.yml',
    'drupal/config/sync/pathauto.pattern.article.yml',
    'drupal/config/sync/pathauto.pattern.concert.yml',
    'drupal/config/sync/pathauto.pattern.forum_topic.yml',
    'drupal/config/sync/pathauto.pattern.stage.yml',
    'drupal/config/sync/pathauto.settings.yml',
    'drupal/config/sync/redirect.settings.yml',
    'drupal/config/sync/system.site.yml',
    'drupal/config/sync/views.view.blog_posts.yml',
    'drupal/config/sync/views.view.forum_topics.yml',
    'drupal/scripts/apply-editorial-alias-policy-2026.sh',
    'drupal/scripts/editorial-canonical-aliases.php',
    'drupal/web/modules/custom/unisonges_structure/unisonges_structure.module',
  ];
  $hashes = [];
  foreach ($relative_files as $relative_file) {
    $path = $repo_root . '/' . $relative_file;
    editorial_alias_exact_file($path);
    $hash = hash_file('sha256', $path);
    if (!is_string($hash)) {
      editorial_alias_fail('Could not hash a reviewed source file.');
    }
    $hashes[$relative_file] = $hash;
  }
  ksort($hashes, SORT_STRING);
  return $hashes;
}

/**
 * Hash every active configuration object in every collection.
 */
function editorial_alias_config_snapshot(): array {
  $storage = \Drupal::service('config.storage');
  $collection_names = [StorageInterface::DEFAULT_COLLECTION];
  foreach ($storage->getAllCollectionNames() as $collection_name) {
    if (!in_array($collection_name, $collection_names, TRUE)) {
      $collection_names[] = $collection_name;
    }
  }
  sort($collection_names, SORT_STRING);

  $snapshot = [];
  foreach ($collection_names as $collection_name) {
    $collection = $collection_name === StorageInterface::DEFAULT_COLLECTION
      ? $storage
      : $storage->createCollection($collection_name);
    $names = $collection->listAll();
    sort($names, SORT_STRING);
    foreach ($names as $name) {
      $data = $collection->read($name);
      if (!is_array($data)) {
        editorial_alias_fail('Could not snapshot active configuration.');
      }
      $snapshot[$collection_name][$name] = editorial_alias_hash_data($data);
    }
  }
  return $snapshot;
}

/**
 * Hash every entity of a type without printing its values.
 */
function editorial_alias_entity_snapshot(string $entity_type_id): array {
  $storage = \Drupal::entityTypeManager()->getStorage($entity_type_id);
  $storage->resetCache();
  $definition = \Drupal::entityTypeManager()->getDefinition($entity_type_id);
  $id_key = $definition->getKey('id');
  if (!is_string($id_key) || $id_key === '') {
    editorial_alias_fail('Entity type has no stable ID key: ' . $entity_type_id . '.');
  }
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->sort($id_key, 'ASC')
    ->execute();
  $snapshot = [];
  foreach ($storage->loadMultiple($ids) as $id => $entity) {
    $snapshot[(string) $id] = editorial_alias_hash_data($entity->toArray());
  }
  ksort($snapshot, SORT_NATURAL);
  return $snapshot;
}

/**
 * Hash every Simple Sitemap entity override without exposing stored settings.
 */
function editorial_alias_simple_sitemap_override_snapshot(): array {
  try {
    $rows = \Drupal::database()
      ->select('simple_sitemap_entity_overrides', 'sitemap_override')
      ->fields('sitemap_override', [
        'id',
        'type',
        'entity_type',
        'entity_id',
        'inclusion_settings',
      ])
      ->orderBy('id', 'ASC')
      ->execute()
      ->fetchAll();
  }
  catch (Throwable) {
    editorial_alias_fail('Simple Sitemap entity overrides could not be snapshotted.');
  }
  $snapshot = [];
  foreach ($rows as $row) {
    if (!is_object($row)
      || !isset($row->id, $row->type, $row->entity_type, $row->entity_id)
      || (!is_string($row->inclusion_settings ?? NULL)
        && ($row->inclusion_settings ?? NULL) !== NULL)
      || !ctype_digit((string) $row->id)
      || (int) $row->id <= 0
      || isset($snapshot[(string) $row->id])) {
      editorial_alias_fail('Simple Sitemap entity override state is malformed or ambiguous.');
    }
    $snapshot[(string) $row->id] = editorial_alias_hash_data([
      'id' => (string) $row->id,
      'type' => (string) $row->type,
      'entity_type' => (string) $row->entity_type,
      'entity_id' => (string) $row->entity_id,
      'inclusion_settings_is_null' => ($row->inclusion_settings ?? NULL) === NULL,
      'inclusion_settings_sha256' => hash(
        'sha256',
        (string) ($row->inclusion_settings ?? ''),
      ),
    ]);
  }
  ksort($snapshot, SORT_NATURAL);
  return $snapshot;
}

/**
 * Hash all persisted node fields except the computed path field.
 */
function editorial_alias_node_snapshot(NodeInterface $node): array {
  $values = $node->toArray();
  unset($values['path']);
  $translation_langcodes = array_keys($node->getTranslationLanguages(FALSE));
  sort($translation_langcodes, SORT_STRING);
  return [
    'id' => (int) $node->id(),
    'uuid' => (string) $node->uuid(),
    'bundle' => (string) $node->bundle(),
    'langcode' => (string) $node->language()->getId(),
    'published' => (bool) $node->isPublished(),
    'revision_id' => (int) $node->getRevisionId(),
    'default_revision' => (bool) $node->isDefaultRevision(),
    'translation_langcodes' => $translation_langcodes,
    'persisted_fields_sha256' => editorial_alias_hash_data($values),
  ];
}

/**
 * Read both persisted and resolved Pathauto state from one verified snapshot.
 */
function editorial_alias_pathauto_state(NodeInterface $node, array $states): array {
  if (!$node->hasField('path')) {
    editorial_alias_fail('A targeted node has no Path field.');
  }
  $key = PathautoState::getPathautoStateKey($node->id());
  $persisted = array_key_exists($key, $states) ? $states[$key] : NULL;
  $resolved = $node->get('path')->first()?->get('pathauto')->getValue();
  $expected_resolved = $persisted ?? PathautoState::CREATE;
  if ($resolved !== $expected_resolved) {
    editorial_alias_fail('A targeted Node Pathauto state read is inconsistent.');
  }
  return [
    'persisted' => $persisted,
    'resolved' => $resolved,
  ];
}

/**
 * Snapshot every persisted Node Pathauto state without exposing it in output.
 */
function editorial_alias_pathauto_state_snapshot(): array {
  $store = \Drupal::keyValue('pathauto_state.node');
  if (get_class($store) !== DatabaseStorage::class
    || $store->getCollectionName() !== 'pathauto_state.node') {
    editorial_alias_fail('The reviewed transactional Pathauto state store is not active.');
  }
  try {
    $connection = \Drupal::database();
    if (!$connection->schema()->tableExists('key_value')) {
      editorial_alias_fail('The Drupal key-value table is unavailable.');
    }
    $stored_count = (int) $connection->select('key_value', 'kv')
      ->condition('collection', 'pathauto_state.node')
      ->countQuery()
      ->execute()
      ->fetchField();
    $states = $store->getAll();
  }
  catch (EditorialAliasPolicyException $exception) {
    throw $exception;
  }
  catch (Throwable) {
    editorial_alias_fail('The persisted Node Pathauto state collection could not be read.');
  }
  if (count($states) !== $stored_count) {
    editorial_alias_fail('The Node Pathauto state API returned an incomplete collection.');
  }
  foreach ($states as $state) {
    if (!in_array($state, [PathautoState::SKIP, PathautoState::CREATE], TRUE)) {
      editorial_alias_fail('The persisted Node Pathauto state collection contains an invalid value.');
    }
  }
  ksort($states, SORT_NATURAL);
  return $states;
}

/**
 * Load all PathAlias entities for one canonical node source.
 */
function editorial_alias_load_for_source(string $source): array {
  $storage = \Drupal::entityTypeManager()->getStorage('path_alias');
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('path', $source)
    ->sort('id', 'ASC')
    ->execute();
  $storage->resetCache($ids);
  return $storage->loadMultiple($ids);
}

/**
 * Count every owner of an alias across languages.
 */
function editorial_alias_owner_count(string $alias): int {
  return (int) \Drupal::entityTypeManager()
    ->getStorage('path_alias')
    ->getQuery()
    ->accessCheck(FALSE)
    ->condition('alias', $alias)
    ->count()
    ->execute();
}

/**
 * Count aliases which collide after Pathauto's configured lowercasing.
 */
function editorial_alias_casefold_owner_count(string $alias): int {
  $storage = \Drupal::entityTypeManager()->getStorage('path_alias');
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->execute();
  $storage->resetCache($ids);
  $expected = mb_strtolower($alias, 'UTF-8');
  $count = 0;
  foreach ($storage->loadMultiple($ids) as $entity) {
    if (mb_strtolower((string) $entity->getAlias(), 'UTF-8') === $expected) {
      $count++;
    }
  }
  return $count;
}

/**
 * Count stored menu links which would make Core rewrite its derived tree.
 */
function editorial_alias_menu_link_reference_count(string $alias): int {
  return (int) \Drupal::entityTypeManager()
    ->getStorage('menu_link_content')
    ->getQuery()
    ->accessCheck(FALSE)
    ->condition('link.uri', 'internal:' . $alias)
    ->count()
    ->execute();
}

/**
 * Match Pathauto's language normalization for alias generation.
 */
function editorial_alias_generation_langcode(NodeInterface $node): string {
  $langcode = $node->language()->getId();
  return $langcode === LanguageInterface::LANGCODE_NOT_APPLICABLE
    ? LanguageInterface::LANGCODE_NOT_SPECIFIED
    : $langcode;
}

/**
 * Determine whether an existing alias is numeric for policy purposes.
 */
function editorial_alias_is_numeric(string $alias, string $bundle): bool {
  if (preg_match('#^/(?:node/)?[0-9]+/?$#D', $alias) === 1) {
    return TRUE;
  }
  $prefix = EDITORIAL_ALIAS_TARGETS[$bundle]['prefix'];
  if (!str_starts_with($alias, $prefix)) {
    return FALSE;
  }
  $slug = substr($alias, strlen($prefix));
  return $slug !== ''
    && preg_match('#^[0-9]+(?:[-/][0-9]+)*$#D', $slug) === 1;
}

/**
 * Enforce conservative syntax for an existing manual alias.
 */
function editorial_alias_has_safe_syntax(string $alias): bool {
  $content = ltrim($alias, '/');
  if (!mb_check_encoding($alias, 'UTF-8')
    || !class_exists(Normalizer::class)
    || !Normalizer::isNormalized($alias, Normalizer::FORM_C)
    || !str_starts_with($alias, '/')
    || $content === ''
    || str_ends_with($alias, '/')
    || preg_match('/\p{L}/u', $content) !== 1
    || str_starts_with($alias, '/node/')
    || str_contains($alias, '//')
    || str_contains($alias, '\\')
    || str_contains($alias, '?')
    || str_contains($alias, '#')
    || str_contains($alias, '%')
    || preg_match('/[\p{C}\p{Z}]/u', $alias) === 1
    || mb_strlen($alias) > EDITORIAL_ALIAS_STORAGE_MAX_LENGTH) {
    return FALSE;
  }
  foreach (explode('/', $alias) as $segment) {
    if ($segment === '.' || $segment === '..') {
      return FALSE;
    }
  }
  return TRUE;
}

/**
 * Enforce the lowercase bundle namespace for an automatic alias.
 */
function editorial_alias_is_safe(string $alias, string $bundle): bool {
  $target = EDITORIAL_ALIAS_TARGETS[$bundle];
  $slug = str_starts_with($alias, $target['prefix'])
    ? substr($alias, strlen($target['prefix']))
    : '';
  return editorial_alias_has_safe_syntax($alias)
    && $alias === mb_strtolower($alias, 'UTF-8')
    && str_starts_with($alias, $target['prefix'])
    && $alias !== $target['hub']
    && $slug !== ''
    && preg_match('/\p{L}/u', $slug) === 1
    && mb_strlen($alias) <= EDITORIAL_ALIAS_MAX_LENGTH;
}

/**
 * Classify one targeted node without exposing alias or content values.
 */
function editorial_alias_classify(
  NodeInterface $node,
  array $redirect_sources,
  array $pathauto_states,
): array {
  $bundle = (string) $node->bundle();
  $source = '/node/' . $node->id();
  $aliases = editorial_alias_load_for_source($source);
  $state = editorial_alias_pathauto_state($node, $pathauto_states);
  $alias_hashes = [];
  foreach ($aliases as $id => $alias_entity) {
    $alias_hashes[(string) $id] = editorial_alias_hash_data($alias_entity->toArray());
  }
  ksort($alias_hashes, SORT_NATURAL);

  if (count($aliases) === 0) {
    $classification = 'no alias';
  }
  elseif (count($aliases) > 1) {
    $classification = 'duplicate/ambiguous alias';
  }
  else {
    $alias_entity = reset($aliases);
    $alias = (string) $alias_entity->getAlias();
    $alias_langcode = $alias_entity->language()->getId();
    $generation_langcode = editorial_alias_generation_langcode($node);
    if (isset($redirect_sources[mb_strtolower(ltrim($alias, '/'), 'UTF-8')])
      || !in_array($alias_langcode, [
        $generation_langcode,
        LanguageInterface::LANGCODE_NOT_SPECIFIED,
      ], TRUE)
      || editorial_alias_owner_count($alias) !== 1
      || editorial_alias_casefold_owner_count($alias) !== 1) {
      $classification = 'duplicate/ambiguous alias';
    }
    elseif (editorial_alias_is_numeric($alias, $bundle)) {
      $classification = 'numeric alias';
    }
    elseif (!$alias_entity->isPublished()
      || !editorial_alias_has_safe_syntax($alias)
      || !in_array($state['persisted'], [NULL, PathautoState::SKIP, PathautoState::CREATE], TRUE)
      || \Drupal::service('path_alias.manager')->getPathByAlias(
        $alias,
        $generation_langcode,
      ) !== $source) {
      $classification = 'malformed alias';
    }
    elseif ($state['persisted'] === PathautoState::SKIP
      || $state['persisted'] === NULL) {
      $classification = 'manual alias';
    }
    elseif (editorial_alias_is_safe($alias, $bundle)) {
      $classification = 'valid unique non-numeric alias';
    }
    else {
      $classification = 'malformed alias';
    }
  }

  return [
    'classification' => $classification,
    'source' => $source,
    'state' => $state,
    'aliases_sha256' => $alias_hashes,
  ];
}

/**
 * Reproduce Pathauto generation up to (but not including) uniquification.
 */
function editorial_alias_base_candidate(
  NodeInterface $node,
  PathautoPatternInterface $active_pattern,
): ?string {
  if (!$node->isDefaultRevision() || !$node->hasField('path')) {
    editorial_alias_fail('Editorial alias generation requires a default-revision Node with a path field.');
  }
  $langcode = editorial_alias_generation_langcode($node);
  $pattern = $active_pattern->getPattern();
  $expected_pattern = EDITORIAL_ALIAS_TARGETS[$node->bundle()]['pattern'] ?? NULL;
  if (!is_string($expected_pattern)
    || !hash_equals($expected_pattern, $pattern)
    || substr_count($pattern, '[node:title]') !== 1) {
    editorial_alias_fail('The title-only editorial pattern changed during generation.');
  }

  // The reviewed patterns contain one simple Core node title token. Core's
  // token flow returns getTitle(), wraps plain token text in HtmlEscapedText,
  // then invokes Pathauto's cleaner callback. Reproduce those exact steps
  // while deliberately avoiding the site's decorated Token service, arbitrary
  // token hooks and ECA events.
  $clean_title = \Drupal::service('pathauto.alias_cleaner')->cleanString(
    new HtmlEscapedText($node->getTitle()),
    [
      'langcode' => $langcode,
      'pathauto' => TRUE,
    ],
  );
  if ($clean_title === '') {
    return NULL;
  }

  $alias = str_replace('[node:title]', $clean_title, $pattern);
  $alias = \Drupal::service('pathauto.alias_cleaner')->cleanAlias($alias);
  return mb_strlen($alias) === 0 ? NULL : $alias;
}

/**
 * Apply Pathauto's deterministic -0, -1, ... rule including this plan.
 */
function editorial_alias_plan_candidate(
  string $base_alias,
  string $source,
  string $langcode,
  array $planned_aliases,
): string {
  $uniquifier = \Drupal::service('pathauto.alias_uniquifier');
  if (!$uniquifier->isReserved($base_alias, $source, $langcode)
    && !isset($planned_aliases[$base_alias])) {
    return $base_alias;
  }

  for ($index = 0; $index < 100000; $index++) {
    $suffix = '-' . $index;
    $candidate = Unicode::truncate(
      $base_alias,
      EDITORIAL_ALIAS_MAX_LENGTH - mb_strlen($suffix),
      TRUE,
    ) . $suffix;
    if (!$uniquifier->isReserved($candidate, $source, $langcode)
      && !isset($planned_aliases[$candidate])) {
      return $candidate;
    }
  }
  editorial_alias_fail('Could not derive a bounded unique alias candidate.');
}

/**
 * Return the set of Redirect source paths which alias insertion would delete.
 */
function editorial_alias_redirect_sources(): array {
  $storage = \Drupal::entityTypeManager()->getStorage('redirect');
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->sort('rid', 'ASC')
    ->execute();
  $storage->resetCache($ids);
  $sources = [];
  foreach ($storage->loadMultiple($ids) as $redirect) {
    $source = $redirect->getSource();
    $path = $source['path'] ?? NULL;
    if (is_string($path)) {
      $sources[mb_strtolower($path, 'UTF-8')] = TRUE;
    }
  }
  return $sources;
}

/**
 * Count rows Redirect 1.12 would delete for a new PathAlias.
 */
function editorial_alias_redirect_insert_delete_match_count(
  string $alias,
  string $langcode,
): int {
  $path = ltrim($alias, '/');
  $connection = \Drupal::database();
  try {
    // Reproduce redirect_delete_by_path($alias, $langcode, FALSE), including
    // the database collation used by its escaped LIKE comparison.
    $query = $connection->select('redirect', 'redirect');
    $query->addField('redirect', 'rid');
    $query_or = $connection->condition('OR');
    $query_or->condition(
      'redirect_source__path',
      $connection->escapeLike($path),
      'LIKE',
    );
    $query->condition('language', $langcode);
    $query->condition($query_or);
    $rids = $query->execute()->fetchCol();
  }
  catch (Throwable) {
    editorial_alias_fail('Redirect insertion-side collision state could not be inspected.');
  }
  return count($rids);
}

/**
 * Verify the two existing public hub aliases cannot be claimed by content.
 */
function editorial_alias_assert_hubs(): void {
  $alias_storage = \Drupal::entityTypeManager()->getStorage('path_alias');
  $alias_storage->resetCache();
  $node_storage = \Drupal::entityTypeManager()->getStorage('node');
  \Drupal::service('path_alias.manager')->cacheClear();
  $redirect_sources = editorial_alias_redirect_sources();
  $owner_ids = [];
  foreach (EDITORIAL_ALIAS_TARGETS as $target) {
    if (isset($redirect_sources[mb_strtolower(ltrim($target['hub'], '/'), 'UTF-8')])) {
      editorial_alias_fail('A public hub alias collides with a Redirect source.');
    }
    $aliases = $alias_storage->loadByProperties(['alias' => $target['hub']]);
    if (count($aliases) !== 1
      || editorial_alias_casefold_owner_count($target['hub']) !== 1) {
      editorial_alias_fail('The public hub alias ' . $target['hub'] . ' must have exactly one owner.');
    }
    $alias = reset($aliases);
    if ((string) $alias->getAlias() !== $target['hub']
      || !$alias->isPublished()
      || !in_array($alias->language()->getId(), [
        'fr',
        LanguageInterface::LANGCODE_NOT_SPECIFIED,
      ], TRUE)) {
      editorial_alias_fail('A public hub PathAlias has an invalid publication or language state.');
    }
    $path = (string) $alias->getPath();
    if (preg_match('#^/node/([1-9][0-9]*)$#D', $path, $matches) !== 1) {
      editorial_alias_fail('A public hub alias does not resolve directly to one node.');
    }
    $node_storage->resetCache([(int) $matches[1]]);
    $node = $node_storage->load((int) $matches[1]);
    if (!$node instanceof NodeInterface
      || $node->bundle() !== 'page'
      || !$node->isPublished()
      || isset($owner_ids[(int) $node->id()])) {
      editorial_alias_fail('A public hub alias does not own a published Basic page.');
    }
    if (\Drupal::service('path_alias.manager')->getPathByAlias(
      $target['hub'],
      'fr',
    ) !== $path) {
      editorial_alias_fail('A public hub alias does not round-trip for French requests.');
    }
    $owner_ids[(int) $node->id()] = TRUE;
  }
}

/**
 * Verify unchanged published-only View and Forum draft protections.
 */
function editorial_alias_assert_access_prerequisites(): void {
  if (!\Drupal::moduleHandler()->moduleExists('unisonges_structure')
    || !function_exists('unisonges_structure_node_access')
    || !function_exists('unisonges_structure_views_query_alter')) {
    editorial_alias_fail('The merged Forum draft access hooks are not active.');
  }
  $forum_status = editorial_alias_active_config('core.base_field_override.node.forum_topic.status');
  if (($forum_status['bundle'] ?? NULL) !== 'forum_topic'
    || ($forum_status['field_name'] ?? NULL) !== 'status'
    || ($forum_status['default_value'][0]['value'] ?? NULL) !== 0) {
    editorial_alias_fail('The Forum Topic unpublished default is not active.');
  }

  foreach ([
    'views.view.blog_posts' => 'article',
    'views.view.forum_topics' => 'forum_topic',
  ] as $config_name => $bundle) {
    $view = editorial_alias_active_config($config_name);
    $options = $view['display']['default']['display_options'] ?? NULL;
    if (!is_array($options)
      || ($view['base_table'] ?? NULL) !== 'node_field_data'
      || ($options['access'] ?? NULL) !== [
        'type' => 'perm',
        'options' => ['perm' => 'access content'],
      ]
      || ($options['filters']['status']['value'] ?? NULL) !== '1'
      || ($options['filters']['type']['value'] ?? NULL) !== [$bundle => $bundle]
      || ($options['query']['options']['disable_sql_rewrite'] ?? NULL) !== FALSE) {
      editorial_alias_fail('A Blog/Forum View lost its publication or node-access boundary.');
    }
  }
}

/**
 * Verify locked modules, settings, patterns, and exact bundle selection.
 */
function editorial_alias_runtime_prerequisites(string $repo_root, string $site_origin): array {
  if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 3) {
    editorial_alias_fail('The reviewed runtime is PHP 8.3.x; active=' . PHP_VERSION . '.');
  }
  if (\Drupal::VERSION !== '11.3.3') {
    editorial_alias_fail('The reviewed Drupal runtime is 11.3.3; active=' . \Drupal::VERSION . '.');
  }
  foreach ([
    'drupal/pathauto' => '1.14.0',
    'drupal/redirect' => '1.12.0',
    'drupal/simple_sitemap' => '4.2.3',
    'drupal/token' => '1.17.0',
  ] as $package => $expected_version) {
    if (!InstalledVersions::isInstalled($package)) {
      editorial_alias_fail('Required locked package is not installed: ' . $package . '.');
    }
    $active_version = ltrim((string) InstalledVersions::getPrettyVersion($package), 'v');
    if ($active_version !== $expected_version) {
      editorial_alias_fail('Locked package version mismatch for ' . $package . '.');
    }
  }
  foreach (['node', 'path', 'path_alias', 'pathauto', 'redirect', 'simple_sitemap', 'token'] as $module) {
    if (!\Drupal::moduleHandler()->moduleExists($module)) {
      editorial_alias_fail('Required module is not active: ' . $module . '.');
    }
  }
  $reviewed_package_trees = editorial_alias_reviewed_package_trees($repo_root);
  if (!class_exists(Normalizer::class)) {
    editorial_alias_fail('The PHP intl Normalizer is required for NFC validation.');
  }
  $transactional_storage_snapshot = editorial_alias_transactional_storage_snapshot(
    \Drupal::database(),
  );
  if ((int) \Drupal::service('pathauto.alias_storage_helper')
    ->getAliasSchemaMaxLength() !== EDITORIAL_ALIAS_STORAGE_MAX_LENGTH) {
    editorial_alias_fail('The PathAlias schema maximum differs from the reviewed baseline.');
  }
  foreach ([
    'pathauto_alias_alter',
    'pathauto_is_alias_reserved',
    'pathauto_pattern_alter',
    'pathauto_punctuation_chars_alter',
    'transliteration_overrides_alter',
  ] as $hook) {
    if (\Drupal::moduleHandler()->hasImplementations($hook)) {
      editorial_alias_fail('An unaudited active alias/transliteration behavior hook is present.');
    }
  }

  $active_origin = strtolower(\Drupal::request()->getSchemeAndHttpHost());
  if (!hash_equals(strtolower($site_origin), $active_origin)) {
    editorial_alias_fail('The bootstrapped site origin differs from the approved origin.');
  }
  $site = editorial_alias_active_config('system.site');
  if (($site['uuid'] ?? NULL) !== EDITORIAL_ALIAS_SITE_UUID
    || ($site['langcode'] ?? NULL) !== 'fr'
    || ($site['default_langcode'] ?? NULL) !== 'fr'
    || \Drupal::languageManager()->getCurrentLanguage()->getId() !== 'fr') {
    editorial_alias_fail('The active Drupal site identity or French language baseline differs.');
  }
  editorial_alias_assert_punctuation_values();

  $sync_dir = $repo_root . '/drupal/config/sync';
  $core_extension_source = editorial_alias_read_yaml($sync_dir . '/core.extension.yml');
  $core_extension_active = editorial_alias_active_config('core.extension');
  if (!editorial_alias_data_equals(
    editorial_alias_without_default_hash($core_extension_source),
    editorial_alias_without_default_hash($core_extension_active),
  )) {
    editorial_alias_fail('The active module/theme inventory differs from reviewed source.');
  }
  $settings_source = editorial_alias_read_yaml($sync_dir . '/pathauto.settings.yml');
  $settings_active = editorial_alias_active_config('pathauto.settings');
  if (!editorial_alias_data_equals($settings_source, $settings_active)
    || ($settings_active['separator'] ?? NULL) !== '-'
    || ($settings_active['max_length'] ?? NULL) !== EDITORIAL_ALIAS_MAX_LENGTH
    || ($settings_active['max_component_length'] ?? NULL) !== EDITORIAL_ALIAS_MAX_LENGTH
    || ($settings_active['transliterate'] ?? NULL) !== TRUE
    || ($settings_active['reduce_ascii'] ?? NULL) !== FALSE
    || ($settings_active['case'] ?? NULL) !== TRUE
    || ($settings_active['punctuation'] ?? NULL) !== ['hyphen' => 1]
    || ($settings_active['update_action'] ?? NULL) !== PathautoGeneratorInterface::UPDATE_ACTION_DELETE) {
    editorial_alias_fail('Active Pathauto settings differ from the reviewed global baseline.');
  }
  $redirect_source = editorial_alias_read_yaml($sync_dir . '/redirect.settings.yml');
  $redirect_active = editorial_alias_active_config('redirect.settings');
  if (!editorial_alias_data_equals($redirect_source, $redirect_active)
    || ($redirect_active['auto_redirect'] ?? NULL) !== TRUE
    || ($redirect_active['default_status_code'] ?? NULL) !== 301) {
    editorial_alias_fail('Active Redirect settings differ from the reviewed global baseline.');
  }

  $pattern_storage = \Drupal::entityTypeManager()->getStorage('pathauto_pattern');
  $all_patterns = $pattern_storage->loadMultiple();
  $active_pattern_ids = array_keys($all_patterns);
  $expected_pattern_ids = ['article', 'concert', 'forum_topic', 'stage'];
  sort($active_pattern_ids, SORT_STRING);
  sort($expected_pattern_ids, SORT_STRING);
  if ($active_pattern_ids !== $expected_pattern_ids) {
    editorial_alias_fail('The active Pathauto pattern inventory differs from reviewed source.');
  }
  $seen_uuids = [];
  foreach ($all_patterns as $pattern) {
    $uuid = (string) $pattern->uuid();
    if ($uuid !== '' && isset($seen_uuids[$uuid])) {
      editorial_alias_fail('Active Pathauto pattern UUIDs are not unique.');
    }
    $seen_uuids[$uuid] = TRUE;
  }

  foreach (['concert', 'stage'] as $legacy_pattern_id) {
    $config_name = 'pathauto.pattern.' . $legacy_pattern_id;
    $source = editorial_alias_read_yaml($sync_dir . '/' . $config_name . '.yml');
    $active = editorial_alias_active_config($config_name);
    if (($source['id'] ?? NULL) !== $legacy_pattern_id
      || !editorial_alias_data_equals(
        editorial_alias_without_default_hash($source),
        editorial_alias_without_default_hash($active),
      )) {
      editorial_alias_fail('An active Stage/Concert pattern differs from reviewed source.');
    }
  }

  $patterns = [];
  foreach (EDITORIAL_ALIAS_TARGETS as $bundle => $target) {
    $source = editorial_alias_read_yaml(
      $sync_dir . '/' . $target['config_name'] . '.yml',
    );
    editorial_alias_assert_pattern_source($bundle, $source);
    $active = editorial_alias_active_config($target['config_name']);
    if (!editorial_alias_data_equals(
      editorial_alias_without_default_hash($source),
      editorial_alias_without_default_hash($active),
    )) {
      editorial_alias_fail('An active editorial Pathauto pattern differs from its reviewed source.');
    }
    $pattern = $pattern_storage->load($target['pattern_id']);
    if (!$pattern instanceof PathautoPatternInterface
      || !$pattern->status()
      || $pattern->getPattern() !== $target['pattern']) {
      editorial_alias_fail('An exact active editorial Pathauto pattern could not be loaded.');
    }
    $patterns[$bundle] = $pattern;
  }

  editorial_alias_assert_hubs();
  editorial_alias_assert_access_prerequisites();

  return [
    'patterns' => $patterns,
    'package_trees_sha256' => editorial_alias_hash_data($reviewed_package_trees),
    'settings_sha256' => editorial_alias_hash_data($settings_active),
    'redirect_settings_sha256' => editorial_alias_hash_data($redirect_active),
    'transactional_storage_sha256' => editorial_alias_hash_data(
      $transactional_storage_snapshot,
    ),
  ];
}

/**
 * Build the complete immutable audit and alias/state operation plan.
 */
function editorial_alias_build_plan(
  string $repo_root,
  string $site_origin,
  string $git_head,
): array {
  $runtime = editorial_alias_runtime_prerequisites($repo_root, $site_origin);
  $source_hashes = editorial_alias_source_inventory($repo_root);
  $config_snapshot = editorial_alias_config_snapshot();
  $path_alias_snapshot = editorial_alias_entity_snapshot('path_alias');
  $redirect_snapshot = editorial_alias_entity_snapshot('redirect');
  $simple_sitemap_override_snapshot = editorial_alias_simple_sitemap_override_snapshot();
  $pathauto_state_snapshot = editorial_alias_pathauto_state_snapshot();
  $redirect_sources = editorial_alias_redirect_sources();

  $node_storage = \Drupal::entityTypeManager()->getStorage('node');
  $node_ids = $node_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', array_keys(EDITORIAL_ALIAS_TARGETS), 'IN')
    ->sort('nid', 'ASC')
    ->execute();
  $node_storage->resetCache($node_ids);
  $nodes = $node_storage->loadMultiple($node_ids);

  $entities = [];
  $operations = [];
  $blockers = [];
  $planned_aliases = [];
  $classification_counts = [];

  foreach ($nodes as $node) {
    if (!$node instanceof NodeInterface
      || !isset(EDITORIAL_ALIAS_TARGETS[$node->bundle()])) {
      editorial_alias_fail('The exact bundle query returned an unexpected entity.');
    }
    $bundle = (string) $node->bundle();
    $selected = $runtime['patterns'][$bundle];

    $audit = editorial_alias_classify(
      $node,
      $redirect_sources,
      $pathauto_state_snapshot,
    );
    $classification = $audit['classification'];
    $classification_counts[$classification] = ($classification_counts[$classification] ?? 0) + 1;
    $entity_plan = [
      'node' => editorial_alias_node_snapshot($node),
      'classification' => $classification,
      'pathauto_state' => $audit['state'],
      'aliases_sha256' => $audit['aliases_sha256'],
      'candidate' => NULL,
      'candidate_sha256' => NULL,
      'blockers' => [],
    ];

    if ($node->language()->getId() !== 'fr'
      || $node->getTranslationLanguages(FALSE) !== []) {
      $entity_plan['blockers'][] = 'unsupported_language_or_translation_topology';
    }
    elseif (in_array($classification, [
      'numeric alias',
      'duplicate/ambiguous alias',
      'malformed alias',
    ], TRUE)) {
      $entity_plan['blockers'][] = 'existing_alias_requires_separate_review';
    }
    elseif ($classification === 'manual alias'
      && $audit['state']['persisted'] !== PathautoState::SKIP) {
      // Preserve unknown-provenance aliases and require a separate review to
      // establish their opt-out marker; never infer or transfer ownership.
      $entity_plan['blockers'][] = 'manual_alias_without_explicit_opt_out';
    }
    elseif ($classification === 'no alias') {
      if ($audit['state']['persisted'] === PathautoState::SKIP
        || $audit['state']['resolved'] !== PathautoState::CREATE) {
        $entity_plan['blockers'][] = 'pathauto_opt_out';
      }
      else {
        $base_alias = editorial_alias_base_candidate($node, $runtime['patterns'][$bundle]);
        if ($base_alias === NULL) {
          $entity_plan['blockers'][] = 'empty_generated_slug';
        }
        else {
          $candidate = editorial_alias_plan_candidate(
            $base_alias,
            $audit['source'],
            editorial_alias_generation_langcode($node),
            $planned_aliases,
          );
          if (!editorial_alias_is_safe($candidate, $bundle)
            || editorial_alias_is_numeric($candidate, $bundle)
            || editorial_alias_owner_count($candidate) !== 0
            || editorial_alias_casefold_owner_count($candidate) !== 0) {
            $entity_plan['blockers'][] = 'unsafe_or_ambiguous_candidate';
          }
          elseif (isset($redirect_sources[mb_strtolower(ltrim($candidate, '/'), 'UTF-8')])) {
            $entity_plan['blockers'][] = 'redirect_source_collision';
          }
          elseif (editorial_alias_redirect_insert_delete_match_count(
            $candidate,
            editorial_alias_generation_langcode($node),
          ) !== 0) {
            $entity_plan['blockers'][] = 'redirect_collation_collision';
          }
          elseif (editorial_alias_menu_link_reference_count($candidate) !== 0) {
            // Core's path_alias_insert hook would otherwise rewrite its
            // derived menu_tree record for a pre-existing internal link.
            $entity_plan['blockers'][] = 'menu_link_candidate_reference';
          }
          else {
            $entity_plan['candidate'] = $candidate;
            $entity_plan['candidate_sha256'] = hash('sha256', $candidate);
            $planned_aliases[$candidate] = (int) $node->id();
            $operations[] = [
              'node_id' => (int) $node->id(),
              'bundle' => $bundle,
              'langcode' => editorial_alias_generation_langcode($node),
              'source' => $audit['source'],
              'candidate' => $candidate,
              'candidate_sha256' => hash('sha256', $candidate),
              'node_snapshot' => $entity_plan['node'],
              'pathauto_state' => $audit['state'],
              'pathauto_state_after' => [
                'persisted' => PathautoState::CREATE,
                'resolved' => PathautoState::CREATE,
              ],
            ];
          }
        }
      }
    }

    foreach ($entity_plan['blockers'] as $blocker) {
      $blockers[] = [
        'node_id' => (int) $node->id(),
        'reason' => $blocker,
      ];
    }
    $entities[(string) $node->id()] = $entity_plan;
  }

  ksort($classification_counts, SORT_STRING);
  $fingerprint_data = [
    'version' => 5,
    'policy' => 'editorial-canonical-aliases-2026',
    'site_uuid' => EDITORIAL_ALIAS_SITE_UUID,
    'site_origin' => strtolower($site_origin),
    'git_head' => $git_head,
    'sources_sha256' => $source_hashes,
    'pathauto_settings_sha256' => $runtime['settings_sha256'],
    'reviewed_package_trees_sha256' => $runtime['package_trees_sha256'],
    'redirect_settings_sha256' => $runtime['redirect_settings_sha256'],
    'transactional_storage_sha256' => $runtime['transactional_storage_sha256'],
    'active_config_sha256' => $config_snapshot,
    'path_alias_entities_sha256' => $path_alias_snapshot,
    'redirect_entities_sha256' => $redirect_snapshot,
    'simple_sitemap_overrides_sha256' => $simple_sitemap_override_snapshot,
    'node_pathauto_state' => $pathauto_state_snapshot,
    'classification_counts' => $classification_counts,
    'entities' => $entities,
    'operations' => $operations,
    'blockers' => $blockers,
  ];
  $fingerprint = editorial_alias_hash_data($fingerprint_data);

  return [
    'fingerprint' => $fingerprint,
    'fingerprint_data' => $fingerprint_data,
    'entities' => $entities,
    'operations' => $operations,
    'blockers' => $blockers,
    'config_snapshot' => $config_snapshot,
    'path_alias_snapshot' => $path_alias_snapshot,
    'redirect_snapshot' => $redirect_snapshot,
    'simple_sitemap_override_snapshot' => $simple_sitemap_override_snapshot,
    'pathauto_state_snapshot' => $pathauto_state_snapshot,
  ];
}

/**
 * Emit only the reviewed privacy-minimized plan fields.
 */
function editorial_alias_emit_plan(array $plan): void {
  foreach ($plan['entities'] as $node_id => $entity_plan) {
    editorial_alias_line(
      'ENTITY',
      'id=' . $node_id
      . ' bundle=' . $entity_plan['node']['bundle']
      . ' publication_state=' . ($entity_plan['node']['published'] ? 'published' : 'unpublished')
      . ' classification="' . $entity_plan['classification'] . '"',
    );
  }
  editorial_alias_line(
    'PLAN_SUMMARY',
    'alias_creates=' . count($plan['operations'])
    . ' pathauto_state_writes=' . count(array_filter(
      $plan['operations'],
      static fn (array $operation): bool => $operation['pathauto_state']['persisted'] !== PathautoState::CREATE,
    ))
    . ' blockers=' . count($plan['blockers']),
  );
  print 'PLAN_FINGERPRINT=' . $plan['fingerprint'] . PHP_EOL;
}

/**
 * Build a plan inside a transaction that is always rolled back.
 */
function editorial_alias_build_rolled_back_plan(
  string $repo_root,
  string $site_origin,
  string $git_head,
  EditorialAliasShutdownGuard $shutdown_guard,
): array {
  $connection = \Drupal::database();
  if ($connection->inTransaction()) {
    editorial_alias_fail('Plan construction requires a root database transaction.');
  }
  $transaction = $shutdown_guard->startRootTransaction(
    $connection,
    'unisonges_editorial_alias_plan_2026',
  );
  try {
    $plan = editorial_alias_build_plan($repo_root, $site_origin, $git_head);
  }
  catch (Throwable $throwable) {
    try {
      $shutdown_guard->rollbackIfArmed();
      $shutdown_guard->finalizeVerifiedRollback($connection, $transaction);
      editorial_alias_reset_runtime();
    }
    catch (Throwable) {
      editorial_alias_fail('Plan construction failed and its transaction could not be rolled back.');
    }
    throw $throwable;
  }
  try {
    $shutdown_guard->rollbackIfArmed();
    $shutdown_guard->finalizeVerifiedRollback($connection, $transaction);
    editorial_alias_reset_runtime();
  }
  catch (Throwable) {
    editorial_alias_fail('The plan transaction could not be rolled back.');
  }
  return $plan;
}

/**
 * Clear runtime caches after a transaction rollback.
 */
function editorial_alias_reset_runtime(): void {
  \Drupal::configFactory()->reset();
  \Drupal::service('pathauto.alias_cleaner')->resetCaches();
  \Drupal::service('path_alias.manager')->cacheClear();
  foreach (['node', 'path_alias', 'redirect', 'pathauto_pattern'] as $entity_type_id) {
    \Drupal::entityTypeManager()->getStorage($entity_type_id)->resetCache();
  }
}

/**
 * Execute an exact alias/state plan atomically and verify every invariant.
 */
function editorial_alias_apply_plan(
  array $plan,
  string $repo_root,
  string $site_origin,
  string $git_head,
  EditorialAliasShutdownGuard $shutdown_guard,
  ?int $write_deadline_ns,
): void {
  if ($plan['blockers'] !== []) {
    editorial_alias_fail('The alias plan is blocked and cannot be applied.');
  }
  if ($plan['operations'] === []) {
    editorial_alias_line(
      'NO_CHANGE',
      'alias_creates=0 pathauto_state_writes=0; every entity is already preserved.',
    );
    return;
  }
  if ($write_deadline_ns === NULL) {
    editorial_alias_fail('A planned write requires a bounded lock deadline.');
  }
  editorial_alias_assert_write_deadline($write_deadline_ns);

  editorial_alias_pathauto_state_snapshot();
  editorial_alias_assert_write_deadline($write_deadline_ns);
  $connection = \Drupal::database();
  if ($connection->inTransaction()) {
    editorial_alias_fail('Apply requires a root database transaction, not an ambient savepoint.');
  }
  $transaction = $shutdown_guard->startRootTransaction(
    $connection,
    'unisonges_editorial_alias_policy_2026',
  );
  editorial_alias_assert_write_deadline($write_deadline_ns);
  $created_ids = [];
  $created_records = [];
  $applied_plan_aliases = [];
  $state_write_count = 0;
  try {
    $transactional_storage_sha256 = editorial_alias_hash_data(
      editorial_alias_transactional_storage_snapshot($connection),
    );
    if (!hash_equals(
      $plan['fingerprint_data']['transactional_storage_sha256'],
      $transactional_storage_sha256,
    )) {
      editorial_alias_fail('Transactional storage changed before the guarded write.');
    }
    editorial_alias_assert_write_deadline($write_deadline_ns);
    foreach ($plan['operations'] as $operation) {
      editorial_alias_assert_write_deadline($write_deadline_ns);
      $current_pathauto_states = editorial_alias_pathauto_state_snapshot();
      $node_storage = \Drupal::entityTypeManager()->getStorage('node');
      $node_storage->resetCache([$operation['node_id']]);
      $node = $node_storage->load($operation['node_id']);
      if (!$node instanceof NodeInterface
        || !editorial_alias_data_equals(
          editorial_alias_node_snapshot($node),
          $operation['node_snapshot'],
        )
        || !editorial_alias_data_equals(
          editorial_alias_pathauto_state($node, $current_pathauto_states),
          $operation['pathauto_state'],
        )) {
        editorial_alias_fail('A targeted entity changed after the locked plan was built.');
      }
      editorial_alias_assert_write_deadline($write_deadline_ns);
      $target = EDITORIAL_ALIAS_TARGETS[$operation['bundle']] ?? NULL;
      $selected = is_array($target)
        ? \Drupal::entityTypeManager()->getStorage('pathauto_pattern')
          ->load($target['pattern_id'])
        : NULL;
      if (!$selected instanceof PathautoPatternInterface
        || !$selected->status()
        || $selected->getPattern() !== ($target['pattern'] ?? NULL)) {
        editorial_alias_fail('The reviewed pattern is unavailable or changed during apply.');
      }
      $redirect_sources = editorial_alias_redirect_sources();
      $audit = editorial_alias_classify(
        $node,
        $redirect_sources,
        $current_pathauto_states,
      );
      if ($audit['classification'] !== 'no alias'
        || $audit['state']['persisted'] === PathautoState::SKIP
        || $audit['state']['resolved'] !== PathautoState::CREATE) {
        editorial_alias_fail('A targeted entity is no longer genuinely alias-free and eligible.');
      }
      if (editorial_alias_owner_count($operation['candidate']) !== 0
        || editorial_alias_casefold_owner_count($operation['candidate']) !== 0
        || isset($redirect_sources[mb_strtolower(ltrim($operation['candidate'], '/'), 'UTF-8')])
        || editorial_alias_redirect_insert_delete_match_count(
          $operation['candidate'],
          $operation['langcode'],
        ) !== 0
        || editorial_alias_menu_link_reference_count($operation['candidate']) !== 0) {
        editorial_alias_fail('A planned alias became reserved before its write.');
      }
      editorial_alias_assert_write_deadline($write_deadline_ns);

      $base_alias = editorial_alias_base_candidate($node, $selected);
      $preview = $base_alias === NULL
        ? NULL
        : editorial_alias_plan_candidate(
          $base_alias,
          $operation['source'],
          $operation['langcode'],
          $applied_plan_aliases,
        );
      if (!is_string($preview)
        || !hash_equals($operation['candidate'], $preview)
        || !hash_equals($operation['candidate_sha256'], hash('sha256', $preview))) {
        editorial_alias_fail('Pathauto cleaning/uniquification no longer returns the immutable planned candidate.');
      }
      editorial_alias_assert_write_deadline($write_deadline_ns);

      if (editorial_alias_redirect_insert_delete_match_count(
        $operation['candidate'],
        $operation['langcode'],
      ) !== 0) {
        editorial_alias_fail('Redirect would delete a collation-equivalent source before insertion.');
      }
      editorial_alias_assert_write_deadline($write_deadline_ns);

      // Pass no existing alias to Pathauto's reviewed storage helper. Its
      // insert branch can only create a PathAlias; it cannot update or transfer
      // an existing entity under the global update_action setting.
      $result = \Drupal::service('pathauto.alias_storage_helper')->save(
        [
          'source' => $operation['source'],
          'alias' => $operation['candidate'],
          'language' => $operation['langcode'],
        ],
        NULL,
        'insert',
      );
      if (!is_array($result)
        || ($result['source'] ?? NULL) !== $operation['source']
        || ($result['alias'] ?? NULL) !== $operation['candidate']
        || ($result['langcode'] ?? NULL) !== $operation['langcode']
        || !isset($result['pid'])
        || (int) $result['pid'] <= 0) {
        editorial_alias_fail('Pathauto did not insert the exact planned PathAlias.');
      }
      editorial_alias_assert_write_deadline($write_deadline_ns);
      $created_id = (int) $result['pid'];
      $applied_plan_aliases[$operation['candidate']] = $operation['source'];

      $pathauto_property = $node->get('path')->first()?->get('pathauto');
      if (!$pathauto_property instanceof PathautoState) {
        editorial_alias_fail('The targeted entity lost its Pathauto state property.');
      }
      $pathauto_property->setValue(PathautoState::CREATE);
      $pathauto_property->persist();
      editorial_alias_assert_write_deadline($write_deadline_ns);
      if ($operation['pathauto_state']['persisted'] !== PathautoState::CREATE) {
        $state_write_count++;
      }
      $after_operation_pathauto_states = editorial_alias_pathauto_state_snapshot();
      if (!editorial_alias_data_equals(
        editorial_alias_pathauto_state($node, $after_operation_pathauto_states),
        $operation['pathauto_state_after'],
      )) {
        editorial_alias_fail('The created alias ownership marker was not persisted.');
      }

      $path_alias_storage = \Drupal::entityTypeManager()->getStorage('path_alias');
      $path_alias_storage->resetCache([$created_id]);
      $created = $path_alias_storage->load($created_id);
      if ($created === NULL
        || (string) $created->getPath() !== $operation['source']
        || (string) $created->getAlias() !== $operation['candidate']
        || $created->language()->getId() !== $operation['langcode']
        || !$created->isPublished()
        || editorial_alias_owner_count($operation['candidate']) !== 1
        || editorial_alias_casefold_owner_count($operation['candidate']) !== 1
        || !editorial_alias_is_safe($operation['candidate'], $operation['bundle'])) {
        editorial_alias_fail('Post-create PathAlias verification failed.');
      }
      $resolved = \Drupal::service('path_alias.manager')->getPathByAlias(
        $operation['candidate'],
        $operation['langcode'],
      );
      if ($resolved !== $operation['source']) {
        editorial_alias_fail('The created alias does not resolve back to its exact owner.');
      }
      $post_create_audit = editorial_alias_classify(
        $node,
        editorial_alias_redirect_sources(),
        $after_operation_pathauto_states,
      );
      if ($post_create_audit['classification'] !== 'valid unique non-numeric alias'
        || !editorial_alias_data_equals(
          $post_create_audit['state'],
          $operation['pathauto_state_after'],
        )) {
        editorial_alias_fail('The created alias does not pass the final classification policy.');
      }
      if (!editorial_alias_data_equals(
        editorial_alias_node_snapshot($node),
        $operation['node_snapshot'],
      )) {
        editorial_alias_fail('Alias creation changed persisted node content.');
      }

      $created_ids[] = $created_id;
      $created_records[] = 'id=' . $operation['node_id']
        . ' bundle=' . $operation['bundle']
        . ' publication_state=' . ($operation['node_snapshot']['published'] ? 'published' : 'unpublished')
        . ' classification="no alias"';
      editorial_alias_assert_write_deadline($write_deadline_ns);
    }

    editorial_alias_assert_write_deadline($write_deadline_ns);
    if (!editorial_alias_data_equals(editorial_alias_config_snapshot(), $plan['config_snapshot'])) {
      editorial_alias_fail('Alias apply changed active configuration.');
    }
    if (!editorial_alias_data_equals(editorial_alias_entity_snapshot('redirect'), $plan['redirect_snapshot'])) {
      editorial_alias_fail('Alias apply changed a Redirect entity.');
    }
    if (!editorial_alias_data_equals(
      editorial_alias_simple_sitemap_override_snapshot(),
      $plan['simple_sitemap_override_snapshot'],
    )) {
      editorial_alias_fail('Alias apply changed a Simple Sitemap entity override.');
    }
    editorial_alias_assert_write_deadline($write_deadline_ns);
    $expected_pathauto_states = $plan['pathauto_state_snapshot'];
    foreach ($plan['operations'] as $operation) {
      $key = PathautoState::getPathautoStateKey($operation['node_id']);
      $expected_pathauto_states[$key] = PathautoState::CREATE;
    }
    ksort($expected_pathauto_states, SORT_NATURAL);
    $final_pathauto_states = editorial_alias_pathauto_state_snapshot();
    if (!editorial_alias_data_equals(
      $final_pathauto_states,
      $expected_pathauto_states,
    )) {
      editorial_alias_fail('Alias apply changed Node Pathauto state outside the exact plan.');
    }

    $after_aliases = editorial_alias_entity_snapshot('path_alias');
    foreach ($plan['path_alias_snapshot'] as $id => $hash) {
      if (($after_aliases[$id] ?? NULL) !== $hash) {
        editorial_alias_fail('Alias apply changed or deleted a pre-existing PathAlias.');
      }
    }
    $new_ids = array_values(array_diff(
      array_keys($after_aliases),
      array_keys($plan['path_alias_snapshot']),
    ));
    $new_ids = array_map('intval', $new_ids);
    sort($new_ids, SORT_NUMERIC);
    sort($created_ids, SORT_NUMERIC);
    if ($new_ids !== $created_ids || count($created_ids) !== count($plan['operations'])) {
      editorial_alias_fail('Alias apply created entities outside the exact plan.');
    }

    foreach ($plan['entities'] as $node_id => $entity_plan) {
      editorial_alias_assert_write_deadline($write_deadline_ns);
      $node_storage = \Drupal::entityTypeManager()->getStorage('node');
      $node_storage->resetCache([(int) $node_id]);
      $node = $node_storage->load((int) $node_id);
      $operation_index = array_search(
        (int) $node_id,
        array_column($plan['operations'], 'node_id'),
        TRUE,
      );
      $expected_pathauto_state = $operation_index === FALSE
        ? $entity_plan['pathauto_state']
        : $plan['operations'][$operation_index]['pathauto_state_after'];
      if (!$node instanceof NodeInterface
        || !editorial_alias_data_equals(
          editorial_alias_node_snapshot($node),
          $entity_plan['node'],
        )
        || !editorial_alias_data_equals(
          editorial_alias_pathauto_state($node, $final_pathauto_states),
          $expected_pathauto_state,
        )) {
        editorial_alias_fail('Final verification found a changed targeted node or Pathauto state.');
      }
    }

    editorial_alias_assert_write_deadline($write_deadline_ns);
    Cache::invalidateTags(array_map(
      static fn (array $operation): string => 'node:' . $operation['node_id'],
      $plan['operations'],
    ));
    editorial_alias_assert_write_deadline($write_deadline_ns);
    $shutdown_guard->commitRootTransaction($transaction, $write_deadline_ns);
  }
  catch (Throwable $throwable) {
    if ($shutdown_guard->commitWasCompleted()) {
      // The alias/state transaction is durable. Never describe a callback or
      // post-commit verification failure as a rollback candidate.
      throw $throwable;
    }
    $apply_problem = $throwable instanceof EditorialAliasPolicyException
      ? $throwable->getMessage()
      : 'Unexpected exception during alias apply.';
    $rollback_problem = FALSE;
    try {
      $shutdown_guard->rollbackIfArmed();
      $shutdown_guard->finalizeVerifiedRollback($connection, $transaction);
      editorial_alias_reset_runtime();
      $restored = editorial_alias_build_rolled_back_plan(
        $repo_root,
        $site_origin,
        $git_head,
        $shutdown_guard,
      );
      if (!hash_equals($plan['fingerprint'], $restored['fingerprint'])) {
        editorial_alias_fail('Persisted policy state differs after transaction rollback.');
      }
    }
    catch (Throwable) {
      $rollback_problem = TRUE;
    }
    if ($rollback_problem) {
      $shutdown_guard->markOutcomeUnknown();
      editorial_alias_fail('Apply failed and rollback verification also failed.');
    }
    editorial_alias_fail('Apply failed; transaction rollback was verified. Cause: ' . $apply_problem);
  }

  foreach ($created_records as $record) {
    editorial_alias_line('CREATED', $record);
  }
  editorial_alias_line('APPLIED_OPERATION_COUNT', (string) count($created_ids));
  editorial_alias_line('APPLIED_STATE_WRITE_COUNT', (string) $state_write_count);
  editorial_alias_line('APPLIED', 'Exact missing-alias/state plan committed atomically.');
}

/**
 * Read one wrapper contract value without PHP's special falsy "0" coercion.
 */
function editorial_alias_environment(string $name): string {
  $value = getenv($name);
  return is_string($value) ? $value : '';
}

/**
 * Release the kernel without dispatching HTTP terminate subscribers.
 *
 * In particular, a direct helper must not trigger Automated Cron after its
 * dry-run rollback or its verified apply commit.
 */
function editorial_alias_close_kernel(DrupalKernel $kernel): void {
  $kernel->shutdown();
}

$mode = editorial_alias_environment('UNISONGES_EDITORIAL_ALIAS_MODE');
$site_origin = editorial_alias_environment('UNISONGES_EDITORIAL_ALIAS_SITE_URI');
$expected_fingerprint = editorial_alias_environment('UNISONGES_EDITORIAL_ALIAS_EXPECT_FINGERPRINT');
$git_head = editorial_alias_environment('UNISONGES_EDITORIAL_ALIAS_GIT_HEAD');
$backup_confirmed = editorial_alias_environment('UNISONGES_EDITORIAL_ALIAS_BACKUP_CONFIRMED');

$kernel = NULL;
$request = NULL;
$database_commit_completed = FALSE;
$database_commit_attempted = FALSE;
$shutdown_guard = NULL;
$error_handler_installed = FALSE;
try {
  if (!in_array($mode, ['dry-run', 'apply'], TRUE)
    || !preg_match('/^[a-f0-9]{40}$/D', $git_head)
    || ($mode === 'dry-run' && ($expected_fingerprint !== '' || $backup_confirmed !== '0'))
    || ($mode === 'apply' && (
      preg_match('/^[a-f0-9]{64}$/D', $expected_fingerprint) !== 1
      || $backup_confirmed !== '1'
    ))) {
    editorial_alias_fail('Invalid internal wrapper contract.');
  }
  $uri_parts = parse_url($site_origin);
  if (!is_array($uri_parts)
    || !in_array($uri_parts['scheme'] ?? NULL, ['http', 'https'], TRUE)
    || !isset($uri_parts['host'])
    || $uri_parts['host'] === ''
    || isset($uri_parts['user'])
    || isset($uri_parts['pass'])
    || isset($uri_parts['query'])
    || isset($uri_parts['fragment'])
    || isset($uri_parts['path'])) {
    editorial_alias_fail('Internal site URI must be the normalized approved root origin.');
  }

  $drupal_root = realpath(__DIR__ . '/../web');
  $repo_root = realpath(__DIR__ . '/../..');
  if ($drupal_root === FALSE
    || $repo_root === FALSE
    || $drupal_root === '/'
    || str_starts_with($drupal_root, '/tmp/')
    || str_starts_with($drupal_root, '/mnt/c/')) {
    editorial_alias_fail('Direct bootstrap refused an unsafe or missing project root.');
  }
  editorial_alias_exact_file($drupal_root . '/autoload.php');
  chdir($drupal_root);
  if (!defined('DRUPAL_ROOT')) {
    define('DRUPAL_ROOT', $drupal_root);
  }
  $autoloader = require $drupal_root . '/autoload.php';
  $shutdown_guard = new EditorialAliasShutdownGuard(
    $database_commit_completed,
    $database_commit_attempted,
  );
  editorial_alias_register_shutdown_guard($shutdown_guard);
  $request = Request::create($site_origin . '/', 'GET');
  $kernel = DrupalKernel::createFromRequest(
    $request,
    $autoloader,
    'prod',
    FALSE,
    $drupal_root,
  );
  $kernel->boot();
  $kernel->preHandle($request);

  // Drupal intentionally treats ordinary E_USER_ERROR as handled/non-fatal.
  // During this helper, convert it to a privacy-safe Throwable so the guarded
  // transaction path must roll back instead of continuing toward commit.
  $previous_error_handler = NULL;
  $previous_error_handler = set_error_handler(
    static function (
      int $severity,
      string $message,
      string $file,
      int $line,
    ) use (&$previous_error_handler): bool {
      if ($severity === E_USER_ERROR) {
        throw new ErrorException(
          'E_USER_ERROR trapped by the editorial alias transaction guard.',
          0,
          $severity,
        );
      }
      if (is_callable($previous_error_handler)) {
        call_user_func(
          $previous_error_handler,
          $severity,
          $message,
          $file,
          $line,
        );
        return TRUE;
      }
      return FALSE;
    },
  );
  $error_handler_installed = TRUE;
  if ($previous_error_handler !== '_drupal_error_handler') {
    restore_error_handler();
    $error_handler_installed = FALSE;
    editorial_alias_fail('The reviewed Drupal error handler was not active.');
  }

  $plan = editorial_alias_build_rolled_back_plan(
    $repo_root,
    $site_origin,
    $git_head,
    $shutdown_guard,
  );
  editorial_alias_emit_plan($plan);
  if ($plan['blockers'] !== []) {
    editorial_alias_fail('The fingerprinted plan contains blockers; no write is permitted.');
  }

  if ($mode === 'dry-run') {
    // The entity classifications and fingerprint above are the complete
    // privacy-minimized dry-run output. No alias, Redirect, configuration, or
    // content write API is reachable in this arm; Drupal may warm caches.
  }
  else {
    if (!hash_equals($plan['fingerprint'], $expected_fingerprint)) {
      editorial_alias_fail('Fingerprint mismatch; rerun dry-run against the current source/site/state.');
    }
    if ((bool) \Drupal::state()->get('system.maintenance_mode', FALSE) !== TRUE) {
      editorial_alias_fail('Apply requires Drupal maintenance mode.');
    }
    if ($plan['operations'] === []) {
      editorial_alias_apply_plan(
        $plan,
        $repo_root,
        $site_origin,
        $git_head,
        $shutdown_guard,
        NULL,
      );
    }
    else {
      $persistent_lock = \Drupal::service('drupal.proxy_original_service.lock.persistent');
      $feature_lock = \Drupal::service('drupal.proxy_original_service.lock');
      if (get_class($persistent_lock) !== PersistentDatabaseLockBackend::class
        || get_class($feature_lock) !== DatabaseLockBackend::class) {
        editorial_alias_fail('The exact reviewed lock backends changed before apply.');
      }
      if (!$persistent_lock->acquire(
        ConfigImporter::LOCK_NAME,
        EDITORIAL_ALIAS_LOCK_TTL_SECONDS,
      )) {
        editorial_alias_fail('Could not acquire Drupal\'s persistent config lock.');
      }
      $feature_lock_acquired = FALSE;
      try {
        if (!$feature_lock->acquire(
          EDITORIAL_ALIAS_LOCK,
          EDITORIAL_ALIAS_LOCK_TTL_SECONDS,
        )) {
          editorial_alias_fail('Could not acquire the editorial alias policy lock.');
        }
        $feature_lock_acquired = TRUE;
        $locked_plan = editorial_alias_build_rolled_back_plan(
          $repo_root,
          $site_origin,
          $git_head,
          $shutdown_guard,
        );
        if (!hash_equals($plan['fingerprint'], $locked_plan['fingerprint'])
          || !hash_equals($expected_fingerprint, $locked_plan['fingerprint'])) {
          editorial_alias_fail('Source/site/entity state changed before the locked write.');
        }
        if (!$persistent_lock->acquire(
          ConfigImporter::LOCK_NAME,
          EDITORIAL_ALIAS_LOCK_TTL_SECONDS,
        ) || !$feature_lock->acquire(
          EDITORIAL_ALIAS_LOCK,
          EDITORIAL_ALIAS_LOCK_TTL_SECONDS,
        )) {
          editorial_alias_fail('Could not renew both locks immediately before apply.');
        }
        editorial_alias_assert_lock_owned(
          $persistent_lock,
          ConfigImporter::LOCK_NAME,
        );
        editorial_alias_assert_lock_owned($feature_lock, EDITORIAL_ALIAS_LOCK);
        $write_deadline_ns = editorial_alias_write_deadline();
        editorial_alias_line(
          'PREWRITE_LOCKED',
          'Exact alias/state plan revalidated; planned_persistent_writes_started=0.',
        );
        editorial_alias_apply_plan(
          $locked_plan,
          $repo_root,
          $site_origin,
          $git_head,
          $shutdown_guard,
          $write_deadline_ns,
        );
      }
      finally {
        if ($feature_lock_acquired) {
          $feature_lock->release(EDITORIAL_ALIAS_LOCK);
        }
        $persistent_lock->release(ConfigImporter::LOCK_NAME);
      }
    }
  }
}
catch (Throwable $throwable) {
  $shutdown_guard_failed = FALSE;
  $kernel_shutdown_failed = FALSE;
  if ($shutdown_guard instanceof EditorialAliasShutdownGuard) {
    try {
      $shutdown_guard->rollbackIfArmed();
    }
    catch (Throwable) {
      // The first-position callback retries an active client or enforces the
      // latched unknown outcome before Core's commit-all callback can run.
      $shutdown_guard_failed = TRUE;
    }
  }
  if ($database_commit_completed) {
    fwrite(
      STDERR,
      'POST_COMMIT_ERROR Alias/state transaction committed; later cleanup failed. Verify exact runtime state before any retry.' . PHP_EOL,
    );
  }
  elseif ($database_commit_attempted
    || ($shutdown_guard instanceof EditorialAliasShutdownGuard
      && $shutdown_guard->outcomeIsUnknown())) {
    fwrite(
      STDERR,
      'TRANSACTION_OUTCOME_UNKNOWN Database rollback/commit outcome is not proven; verify exact runtime state and restore the approved backup before any retry.' . PHP_EOL,
    );
  }
  else {
    $message = $throwable instanceof EditorialAliasPolicyException
      ? $throwable->getMessage()
      : 'Unexpected helper failure; no reviewed operation completed.';
    fwrite(STDERR, 'REFUSE ' . $message . PHP_EOL);
  }
  if (!$shutdown_guard_failed && $kernel instanceof DrupalKernel) {
    try {
      editorial_alias_close_kernel($kernel);
    }
    catch (Throwable) {
      // The original fail-closed error remains authoritative.
      $kernel_shutdown_failed = TRUE;
    }
  }
  if ($error_handler_installed) {
    restore_error_handler();
    $error_handler_installed = FALSE;
  }
  if (!$shutdown_guard_failed
    && !$kernel_shutdown_failed
    && $shutdown_guard instanceof EditorialAliasShutdownGuard) {
    try {
      $shutdown_guard->allowShutdownReturn();
    }
    catch (Throwable) {
      // The registered callback will keep the non-zero exit fail-closed.
    }
  }
  exit(1);
}

if ($kernel instanceof DrupalKernel) {
  try {
    editorial_alias_close_kernel($kernel);
  }
  catch (Throwable) {
    $shutdown_status = $database_commit_completed
      ? 'POST_COMMIT_ERROR Alias/state transaction committed; Drupal shutdown failed. Verify exact runtime state before any retry.'
      : 'REFUSE Drupal shutdown failed; no alias/state transaction was committed.';
    fwrite(STDERR, $shutdown_status . PHP_EOL);
    exit(1);
  }
}
if ($error_handler_installed) {
  restore_error_handler();
  $error_handler_installed = FALSE;
}
if ($shutdown_guard instanceof EditorialAliasShutdownGuard) {
  try {
    $shutdown_guard->allowShutdownReturn();
  }
  catch (Throwable) {
    if ($database_commit_completed) {
      $completion_status = 'POST_COMMIT_ERROR Alias/state transaction committed; safe process completion could not be verified.';
    }
    elseif ($database_commit_attempted || $shutdown_guard->outcomeIsUnknown()) {
      $completion_status = 'TRANSACTION_OUTCOME_UNKNOWN The helper could not verify safe process completion.';
    }
    else {
      $completion_status = 'REFUSE The helper could not verify safe process completion; no alias/state transaction was committed.';
    }
    fwrite(
      STDERR,
      $completion_status . PHP_EOL,
    );
    exit(1);
  }
}
