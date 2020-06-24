<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\console\controllers;

use Craft;
use craft\base\PluginInterface;
use craft\console\ControllerTrait;
use craft\db\MigrationManager;
use craft\errors\InvalidPluginException;
use craft\errors\MigrateException;
use craft\errors\MigrationException;
use craft\events\RegisterMigratorEvent;
use craft\helpers\ArrayHelper;
use craft\helpers\FileHelper;
use yii\base\ErrorException;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\console\controllers\BaseMigrateController;
use yii\console\Exception;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Manages Craft and plugin migrations.
 *
 * A migration means a set of persistent changes to the application environment that is shared among different
 * developers. For example, in an application backed by a database, a migration may refer to a set of changes to
 * the database, such as creating a new table, adding a new table column.
 * This controller provides support for tracking the migration history, updating migrations, and creating new
 * migration skeleton files.
 * The migration history is stored in a database table named `migrations`. The table will be automatically
 * created the first time this controller is executed, if it does not exist.
 * Below are some common usages of this command:
 * ~~~
 * # creates a new migration named 'create_user_table' for a plugin with the handle pluginHandle.
 * craft migrate/create create_user_table --plugin=pluginHandle
 * # applies ALL new migrations for a plugin with the handle pluginHandle
 * craft migrate up --plugin=pluginHandle
 * ~~~
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0.0
 */
class MigrateController extends BaseMigrateController
{
    use ControllerTrait;

    /**
     * @event RegisterMigratorEvent The event that is triggered when resolving an unknown migration track.
     *
     * ```php
     * use craft\console\controllers\MigrateController;
     * use craft\db\MigrationManager;
     * use craft\events\RegisterMigratorEvent;
     * use yii\base\Event;
     *
     * Event::on(
     *     MigrateController::class,
     *     MigrateController::EVENT_REGISTER_MIGRATOR,
     *     function(RegisterMigratorEvent $event) {
     *         if ($event->track === 'myCustomTrack') {
     *             $event->migrator = Craft::createObject([
     *                 'class' => MigrationManager::class,
     *                 'track' => 'myCustomTrack',
     *                 'migrationNamespace' => 'my\migration\namespace',
     *                 'migrationPath' => '/path/to/migrations',
     *             ]);
     *             $event->handled = true;
     *         }
     *     }
     * );
     * ```
     *
     * @since 3.5.0
     */
    const EVENT_REGISTER_MIGRATOR = 'registerMigrator';

    /**
     * @var string The migration track to work with (e.g. `craft`, `content`, `plugin:commerce`, etc.)
     *
     * If --plugin is passed, this will automatically be set to the plugin’s track. Otherwise defaults to 'content'.
     * @since 3.5.0
     */
    public $track = MigrationManager::TRACK_CONTENT;

    /**
     * @var string|null The type of migrations we're dealing with here. Can be 'app', 'plugin', or 'content'.
     * @deprecated in 3.5.0. Use [[track]] instead.
     */
    public $type;

    /**
     * @var string|PluginInterface|null The handle of the plugin to use during migration operations, or the plugin itself
     */
    public $plugin;

    /**
     * @var bool Exclude pending content migrations.
     */
    public $noContent = false;

    /**
     * @var bool Skip backing up the database.
     * @since 3.4.3
     */
    public $noBackup = false;

    /**
     * @var MigrationManager|null The migration manager that will be used in this request
     */
    private $_migrator;

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        $this->templateFile = Craft::getAlias('@app/updates/migration.php.template');
    }

    /**
     * Returns the names of valid options for the action (id)
     * An option requires the existence of a public member variable whose
     * name is the option name.
     * Child classes may override this method to specify possible options.
     *
     * Note that the values setting via options are not available
     * until [[beforeAction()]] is being called.
     *
     * @param string $actionID the action id of the current request
     * @return string[] the names of the options valid for the action
     */
    public function options($actionID)
    {
        $options = parent::options($actionID);

        // Remove options we end up overriding
        ArrayHelper::removeValue($options, 'migrationPath');
        ArrayHelper::removeValue($options, 'migrationNamespaces');
        ArrayHelper::removeValue($options, 'compact');

        if ($actionID === 'all') {
            $options[] = 'noBackup';
            $options[] = 'noContent';
        } else {
            $options[] = 'type';
            $options[] = 'track';
            $options[] = 'plugin';
        }

        return $options;
    }

    /**
     * @inheritdoc
     */
    public function optionAliases()
    {
        $aliases = parent::optionAliases();
        $aliases['t'] = 'type';
        $aliases['p'] = 'plugin';

        return $aliases;
    }

    /**
     * @inheritdoc
     */
    public function beforeAction($action)
    {
        if ($action->id !== 'all') {
            // Validate $type
            if ($this->type) {
                switch ($this->type) {
                    case 'app':
                        $this->track = MigrationManager::TRACK_CRAFT;
                        $new = "--track=$this->track";
                        break;
                    case 'content':
                        $this->track = 'content';
                        $new = "--track=$this->track";
                        break;
                    case 'plugin':
                        $this->track = null;
                        $new = "--plugin=$this->plugin";
                        break;
                    default:
                        $this->stderr("Invalid --type option. Allowed values are 'app', 'plugin', or 'content'." . PHP_EOL, Console::FG_RED);
                        return false;
                }

                $this->stdout("The --type option has been deprecated. Use $new instead." . PHP_EOL, Console::FG_YELLOW);
            }

            if ($this->plugin) {
                $this->track = "plugin:$this->plugin";
            } else if ($this->track && preg_match('/^plugin:([\w\-]+)$/', $this->track, $match)) {
                $this->plugin = $match[1];
            }

            // Validate $plugin
            if ($this->plugin) {
                // Make sure $this->plugin in set to a valid plugin handle
                if (empty($this->plugin)) {
                    $this->stderr('You must specify the plugin handle using the --plugin option.' . PHP_EOL, Console::FG_RED);
                    return false;
                }
                $pluginsService = Craft::$app->getPlugins();
                if (($plugin = $pluginsService->getPlugin($this->plugin)) === null) {
                    try {
                        $plugin = $pluginsService->createPlugin($this->plugin);
                    } catch (InvalidPluginException $e) {
                        $this->stderr("Invalid plugin handle: $this->plugin" . PHP_EOL, Console::FG_RED);
                        return false;
                    }
                }
                $this->plugin = $plugin;
            }

            $this->migrationPath = $this->getMigrator()->migrationPath;
            FileHelper::createDirectory($this->migrationPath);
        }

        if (!parent::beforeAction($action)) {
            return false;
        }

        return true;
    }

    /**
     * Creates a new migration.
     *
     * This command creates a new migration using the available migration template.
     * After using this command, developers should modify the created migration
     * skeleton by filling up the actual migration logic.
     *
     * ```
     * craft migrate/create create_news_section
     * ```
     *
     * By default the migration will be created within the project's migrations/
     * folder (as a "content migration").
     * Use `--plugin=<plugin-handle>` to create a new plugin migration.
     * Use `--type=app` to create a new Craft CMS app migration.
     *
     * @param string $name the name of the new migration. This should only contain
     * letters, digits, and underscores.
     * @throws Exception if the name argument is invalid.
     */
    public function actionCreate($name)
    {
        if (!preg_match('/^\w+$/', $name)) {
            throw new Exception('The migration name should contain letters, digits and/or underscore characters only.');
        }

        if ($isInstall = (strcasecmp($name, 'install') === 0)) {
            $name = 'Install';
        } else {
            $name = 'm' . gmdate('ymd_His') . '_' . $name;
        }

        $file = $this->migrationPath . DIRECTORY_SEPARATOR . $name . '.php';

        if ($this->confirm("Create new migration '$file'?")) {
            $templateFile = Craft::getAlias($this->templateFile);

            if ($templateFile === false) {
                throw new Exception('There was a problem getting the template file path');
            }

            $content = $this->renderFile($templateFile, [
                'isInstall' => $isInstall,
                'namespace' => $this->getMigrator()->migrationNamespace,
                'className' => $name
            ]);

            FileHelper::writeToFile($file, $content);
            $this->stdout('New migration created successfully.' . PHP_EOL, Console::FG_GREEN);
        }
    }

    /**
     * Runs all pending Craft, plugin, and content migrations.
     *
     * @return int
     * @throws MigrateException
     */
    public function actionAll(): int
    {
        $updatesService = Craft::$app->getUpdates();
        $db = Craft::$app->getDb();

        // Get the handles in need of an update
        $handles = $updatesService->getPendingMigrationHandles(!$this->noContent);

        // Anything to update?
        if (!empty($handles)) {
            // Enable maintenance mode
            Craft::$app->enableMaintenanceMode();

            // Backup the DB?
            if (!$this->noBackup && Craft::$app->getConfig()->getGeneral()->getBackupOnUpdate()) {
                try {
                    $backupPath = $db->backup();
                } catch (\Throwable $e) {
                    Craft::$app->disableMaintenanceMode();
                    $this->stderr("Error backing up the database: {$e->getMessage()}" . PHP_EOL, Console::FG_RED);
                    return ExitCode::UNSPECIFIED_ERROR;
                }
            }

            // Run the migrations
            try {
                $updatesService->runMigrations($handles);
            } catch (MigrationException $e) {
                // Do we have a backup?
                $restored = false;
                if (!empty($backupPath)) {
                    // Attempt a restore
                    try {
                        $db->restore($backupPath);
                        $restored = true;
                    } catch (\Throwable $restoreException) {
                        // Just log it
                        Craft::$app->getErrorHandler()->logException($restoreException);
                    }
                }

                $error = 'An error occurred running nuw migrations.';
                if ($restored) {
                    $error .= ' The database has been restored to its previous state.';
                } else if (isset($restoreException)) {
                    $error .= ' The database could not be restored due to a separate error: ' . $restoreException->getMessage();
                } else {
                    $error .= ' The database has not been restored.';
                }

                Craft::$app->disableMaintenanceMode();
                $this->stderr($error . PHP_EOL, Console::FG_RED);
                Craft::error($error, __METHOD__);
                Craft::$app->getErrorHandler()->logException($e);
                return ExitCode::UNSPECIFIED_ERROR;
            }

            Craft::$app->disableMaintenanceMode();
        }

        $this->stdout('Migrated up successfully.' . PHP_EOL, Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Upgrades the application by applying new migrations.
     *
     * For example,
     *
     * ```
     * craft migrate     # apply all new migrations
     * craft migrate 3   # apply the first 3 new migrations
     * ```
     *
     * @param int $limit the number of new migrations to be applied. If 0, it means
     * applying all available new migrations.
     *
     * @return int the status of the action execution. 0 means normal, other values mean abnormal.
     */
    public function actionUp($limit = 0)
    {
        $res = parent::actionUp($limit) ?? ExitCode::OK;

        if ($res === ExitCode::OK && empty($this->getNewMigrations())) {
            // Update any schema versions.
            if ($this->track === MigrationManager::TRACK_CRAFT) {
                Craft::$app->getUpdates()->updateCraftVersionInfo();
            } else if ($this->plugin) {
                Craft::$app->getUpdates()->setNewPluginInfo($this->plugin);
            }

            // Delete all compiled templates
            try {
                FileHelper::clearDirectory(Craft::$app->getPath()->getCompiledTemplatesPath(false));
            } catch (InvalidArgumentException $e) {
                // the directory doesn't exist
            } catch (ErrorException $e) {
                Craft::error('Could not delete compiled templates: ' . $e->getMessage());
                Craft::$app->getErrorHandler()->logException($e);
            }
        }

        return $res;
    }

    /**
     * Returns the migration manager that should be used for this request
     *
     * @return MigrationManager
     * @throws InvalidConfigException
     */
    public function getMigrator(): MigrationManager
    {
        if ($this->_migrator === null) {
            if ($this->plugin) {
                $this->_migrator = $this->plugin->getMigrator();
            } else {
                switch ($this->track) {
                    case MigrationManager::TRACK_CRAFT:
                        $this->_migrator = Craft::$app->getMigrator();
                        break;
                    case MigrationManager::TRACK_CONTENT:
                        $this->_migrator = Craft::$app->getContentMigrator();
                        break;
                    default:
                        // Give plugins & modules a chance to register a custom migrator
                        $event = new RegisterMigratorEvent([
                            'track' => $this->track,
                        ]);
                        $this->trigger(self::EVENT_REGISTER_MIGRATOR, $event);
                        if (!$event->migrator) {
                            throw new InvalidConfigException("Invalid migration track: $this->track");
                        }
                        $this->_migrator = $event->migrator;
                }
            }
        }

        return $this->_migrator;
    }

    /**
     * @inheritdoc
     */
    protected function createMigration($class)
    {
        return $this->getMigrator()->createMigration($class);
    }

    /**
     * @inheritdoc
     */
    protected function getNewMigrations()
    {
        return $this->getMigrator()->getNewMigrations();
    }

    /**
     * @inheritdoc
     */
    protected function getMigrationHistory($limit)
    {
        $history = $this->getMigrator()->getMigrationHistory((int)$limit);

        // Convert values to unix timestamps
        $history = array_map('strtotime', $history);

        return $history;
    }

    /**
     * @inheritdoc
     */
    protected function addMigrationHistory($version)
    {
        $this->getMigrator()->addMigrationHistory($version);
    }

    /**
     * @inheritdoc
     */
    protected function removeMigrationHistory($version)
    {
        $this->getMigrator()->removeMigrationHistory($version);
    }

    /**
     * @inheritdoc
     */
    protected function truncateDatabase()
    {
        $this->getMigrator()->truncateHistory();
    }
}
