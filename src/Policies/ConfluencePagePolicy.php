<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Zynqa\FilamentConfluence\Models\ConfluencePage;

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
     * Determine if the user can view a specific Confluence page
     * Implements additive access with exclusions: (space-based OR page-based OR descendant-based) AND NOT excluded
     */
    public function view($user, ConfluencePage $page): bool
    {
        // Super admins can view everything
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // 1. Check if page is explicitly excluded (exclusions take priority)
        if (method_exists($user, 'isConfluencePageExcluded')
            && $user->isConfluencePageExcluded($page->page_id)
        ) {
            return false;
        }

        // 2. Check if page is a descendant of an excluded page with exclude_descendants=true
        if (method_exists($user, 'getConfluenceExcludedPages')) {
            $service = app(\Zynqa\FilamentConfluence\Services\ConfluenceService::class);

            foreach ($user->getConfluenceExcludedPages() as $exclusion) {
                if ($exclusion['exclude_descendants']) {
                    // Check if current page is a descendant of excluded page
                    $descendants = $service->getPageDescendants($exclusion['page_id']);
                    if (collect($descendants)->contains('id', $page->page_id)) {
                        return false;
                    }
                }
            }
        }

        // 3. Check space-based access
        if (method_exists($user, 'getConfluenceSpaceKeys')) {
            $userSpaceKeys = $user->getConfluenceSpaceKeys();
            $pageSpaceKey = $page->space_key;

            if (in_array($pageSpaceKey, $userSpaceKeys)) {
                return true;
            }
        }

        // 4. Check explicit page assignment
        if (method_exists($user, 'hasConfluencePageAccess')
            && $user->hasConfluencePageAccess($page->page_id)
        ) {
            return true;
        }

        // 5. Check if page is a descendant of an assigned page with include_descendants=true
        if (method_exists($user, 'getConfluencePageAssignments')) {
            $service = app(\Zynqa\FilamentConfluence\Services\ConfluenceService::class);

            foreach ($user->getConfluencePageAssignments() as $assignment) {
                if ($assignment['include_descendants']) {
                    // This check can be expensive - consider caching
                    $descendants = $service->getPageDescendants($assignment['page_id']);
                    if (collect($descendants)->contains('id', $page->page_id)) {
                        return true;
                    }
                }
            }
        }

        return false;
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
