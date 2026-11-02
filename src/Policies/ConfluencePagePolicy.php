<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Zynqa\FilamentConfluence\Models\ConfluencePage;
use Zynqa\FilamentConfluence\Services\ConfluenceService;
use Zynqa\FilamentConfluence\Support\SpaceAllowlist;

class ConfluencePagePolicy
{
    use HandlesAuthorization;

    /**
     * Determine if the user can view any Confluence pages
     */
    public function viewAny($user): bool
    {
        // Check if user has ANY Confluence access (spaces or pages)
        if (method_exists($user, 'hasConfluenceAccess')) {
            return $user->hasConfluenceAccess();
        }

        // Fallback: check for space keys only (backwards compatibility)
        if (method_exists($user, 'hasConfluenceSpaceKeys')) {
            return $user->hasConfluenceSpaceKeys();
        }

        return false;
    }

    /**
     * Determine if the user can view a specific Confluence page.
     *
     * Regular users: (assigned space OR assigned page OR descendant of an assigned page) AND
     * NOT (excluded page OR descendant of an excluded page), as worked out by
     * ConfluenceService::canUserSeePage(), the same check the image proxy and search use.
     * Super admins can view every page. The optional allowed_spaces list applies to everyone.
     */
    public function view($user, ConfluencePage $page): bool
    {
        if (! SpaceAllowlist::allows($page->space_key)) {
            return false;
        }

        if (ConfluenceService::isSuperAdmin($user)) {
            return true;
        }

        return app(ConfluenceService::class)->canUserSeePage($user, (string) $page->page_id);
    }

    /**
     * Determine if the user can create Confluence pages
     * Always false - pages are created in Confluence
     */
    public function create($user): bool
    {
        return false;
    }

    /**
     * Determine if the user can update a Confluence page
     * Always false - pages are edited in Confluence
     */
    public function update($user, ConfluencePage $page): bool
    {
        return false;
    }

    /**
     * Determine if the user can delete a Confluence page
     * Always false - pages are deleted in Confluence
     */
    public function delete($user, ConfluencePage $page): bool
    {
        return false;
    }

    /**
     * Determine if the user can restore a Confluence page
     * Always false - not applicable
     */
    public function restore($user, ConfluencePage $page): bool
    {
        return false;
    }

    /**
     * Determine if the user can permanently delete a Confluence page
     * Always false - not applicable
     */
    public function forceDelete($user, ConfluencePage $page): bool
    {
        return false;
    }

    /**
     * Determine if the user can replicate a Confluence page
     * Always false - not applicable
     */
    public function replicate($user, ConfluencePage $page): bool
    {
        return false;
    }
}
