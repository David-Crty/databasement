<?php

namespace App\Enums;

enum UserRole: string
{
    case Demo = 'demo';
    case Viewer = 'viewer';
    case Operator = 'operator';
    case Member = 'member';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('Admin'),
            self::Member => __('Member'),
            self::Operator => __('Operator'),
            self::Viewer => __('Viewer'),
            self::Demo => __('Demo'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Admin => 'o-shield-check',
            self::Member => 'o-pencil-square',
            self::Operator => 'o-play-circle',
            self::Viewer => 'o-eye',
            self::Demo => 'o-beaker',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Admin => 'badge-primary',
            self::Member => 'badge-info',
            self::Operator => 'badge-accent',
            self::Viewer => 'badge-neutral',
            self::Demo => 'badge-ghost',
        };
    }

    /**
     * Roles that can be assigned through the UI (excludes Demo).
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [self::Viewer, self::Operator, self::Member, self::Admin];
    }

    /**
     * Whether this role meets or exceeds the given minimum role.
     */
    public function meetsMinimum(self $required): bool
    {
        return $this->level() >= $required->level();
    }

    public function level(): int
    {
        return match ($this) {
            self::Demo => 0,
            self::Viewer => 1,
            self::Operator => 2,
            self::Member => 3,
            self::Admin => 4,
        };
    }

    /**
     * Validation rule string for assignable roles.
     */
    public static function validationRule(): string
    {
        return 'in:'.implode(',', array_map(fn (self $r) => $r->value, self::assignable()));
    }
}
