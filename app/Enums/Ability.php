<?php

namespace App\Enums;

/**
 * The curated, code-defined catalogue of abilities surfaced in the role
 * management UI as per-role toggles. Admins never free-type ability names;
 * the set of abilities only changes with a code change here.
 *
 * Abilities are global definitions (shared across organizations). Only the
 * assignment of roles to users is scoped per organization. See
 * AppServiceProvider::registerBouncer().
 *
 * The catalogue covers organization-level operations and configuration,
 * including backup settings and notification channels. Truly global concerns
 * (organizations, authentication/SSO and role management) remain reserved for
 * super admins and are not represented here.
 */
enum Ability: string
{
    case RunBackups = 'run-backups';
    case DownloadSnapshots = 'download-snapshots';
    case DeleteSnapshots = 'delete-snapshots';
    case OperateRestores = 'operate-restores';
    case UseAdminer = 'use-adminer';
    case ManageDatabaseServers = 'manage-database-servers';
    case ManageVolumes = 'manage-volumes';
    case ManageAgents = 'manage-agents';
    case ManageBackupSettings = 'manage-backup-settings';
    case ManageNotifications = 'manage-notifications';
    case ManageUsers = 'manage-users';

    /**
     * Short human label shown as the toggle title.
     */
    public function label(): string
    {
        return match ($this) {
            self::RunBackups => __('Run backups'),
            self::DownloadSnapshots => __('Download snapshots'),
            self::DeleteSnapshots => __('Delete snapshots'),
            self::OperateRestores => __('Operate restores'),
            self::UseAdminer => __('Use Adminer'),
            self::ManageDatabaseServers => __('Manage database servers'),
            self::ManageVolumes => __('Manage volumes'),
            self::ManageAgents => __('Manage agents'),
            self::ManageBackupSettings => __('Manage backup settings'),
            self::ManageNotifications => __('Manage notifications'),
            self::ManageUsers => __('Manage users'),
        };
    }

    /**
     * Longer explanation shown beneath the toggle.
     */
    public function description(): string
    {
        return match ($this) {
            self::RunBackups => __('Run backups on demand.'),
            self::DownloadSnapshots => __('Download snapshot files.'),
            self::DeleteSnapshots => __('Delete snapshots and cancel pending backup jobs.'),
            self::OperateRestores => __('Restore from snapshots and manage scheduled restores.'),
            self::UseAdminer => __('Open the Adminer database browser.'),
            self::ManageDatabaseServers => __('Create, edit and delete database server connections.'),
            self::ManageVolumes => __('Create, edit and delete storage volumes.'),
            self::ManageAgents => __('Create, edit, delete and regenerate tokens for remote agents.'),
            self::ManageBackupSettings => __('Configure global backup settings and schedules, and run cleanup and verification.'),
            self::ManageNotifications => __('Create, edit, delete and test notification channels.'),
            self::ManageUsers => __('Invite, edit and remove users in the organization.'),
        };
    }

    /**
     * Grouping used to organise the toggles in the UI.
     */
    public function group(): string
    {
        return match ($this) {
            self::RunBackups,
            self::DownloadSnapshots,
            self::DeleteSnapshots,
            self::OperateRestores,
            self::UseAdminer => __('Operations'),
            self::ManageDatabaseServers,
            self::ManageVolumes,
            self::ManageAgents,
            self::ManageBackupSettings,
            self::ManageNotifications,
            self::ManageUsers => __('Configuration'),
        };
    }

    /**
     * All ability names as strings.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(fn (self $ability) => $ability->value, self::cases());
    }

    /**
     * Abilities organised by {@see group()}, for rendering grouped toggle grids.
     *
     * @return array<string, list<self>>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::cases() as $ability) {
            $groups[$ability->group()][] = $ability;
        }

        return $groups;
    }
}
