<?php

namespace App\Support\Filament\Concerns;

use App\Enums\ContentStatus;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared authorization for the publication state of CMS content.
 *
 * The content.* family splits editing from publishing: content.update covers
 * the body of a record, while content.publish covers whether the public site
 * can see it. Every resource whose status can reach Published goes through
 * here so the rule is written once.
 *
 * Two layers enforce it. The status field is offered without the Published
 * option, and Filament validates a Select against its own options, so a
 * crafted submission is rejected by validation rather than applied. Behind
 * that, assertMayApplyStatus() re-checks the payload against the record read
 * from the database, which also covers a payload that never went through the
 * field at all.
 */
trait GuardsContentPublishing
{
    public static function canPublishContent(): bool
    {
        return (bool) auth('admin')->user()?->hasPermissionTo('content.publish');
    }

    /**
     * The status choices the current admin may actually apply.
     *
     * A record that is already published keeps the option so the field can
     * render its own value; it is locked in that case, which leaves every
     * other field editable without touching the publication state.
     *
     * @return array<string, string>
     */
    public static function publishableStatusOptions(?Model $record = null): array
    {
        $options = [
            ContentStatus::Draft->value => __('admin.cms.status_draft'),
            ContentStatus::Published->value => __('admin.cms.status_published'),
            ContentStatus::Archived->value => __('admin.cms.status_archived'),
        ];

        if (static::canPublishContent() || static::recordStatus($record) === ContentStatus::Published->value) {
            return $options;
        }

        unset($options[ContentStatus::Published->value]);

        return $options;
    }

    /**
     * Leaving Published is a publication change too, so an already-published
     * record's status is frozen for an admin who cannot publish.
     */
    public static function isStatusLocked(?Model $record = null): bool
    {
        return static::recordStatus($record) === ContentStatus::Published->value
            && ! static::canPublishContent();
    }

    /**
     * The status a new record starts on. A resource that would default to
     * Published falls back to Draft for an admin who cannot publish, so
     * creating content stays possible without granting publication.
     */
    public static function defaultPublishableStatus(ContentStatus $preferred = ContentStatus::Draft): string
    {
        if ($preferred !== ContentStatus::Published || static::canPublishContent()) {
            return $preferred->value;
        }

        return ContentStatus::Draft->value;
    }

    public static function statusLockedHint(?Model $record = null): ?string
    {
        return static::isStatusLocked($record)
            ? __('admin.cms.publish_permission_required')
            : null;
    }

    /**
     * Refuses a payload that would move a record into or out of Published
     * without content.publish. A status that is unchanged, or a change between
     * two unpublished states, is ordinary editing and passes through.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws Halt
     */
    public static function assertMayApplyStatus(array $data, ?Model $record = null): void
    {
        if (! array_key_exists('status', $data)) {
            return;
        }

        $submitted = $data['status'] instanceof ContentStatus ? $data['status']->value : $data['status'];
        $current = static::recordStatus($record);

        if ($submitted === $current || static::canPublishContent()) {
            return;
        }

        if ($submitted !== ContentStatus::Published->value && $current !== ContentStatus::Published->value) {
            return;
        }

        Notification::make()
            ->title(__('admin.cms.publish_unauthorized'))
            ->danger()
            ->send();

        throw new Halt;
    }

    private static function recordStatus(?Model $record): ?string
    {
        $status = $record?->getAttribute('status');

        return $status instanceof ContentStatus ? $status->value : $status;
    }
}
