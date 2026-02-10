<?php

declare(strict_types=1);

namespace Zynqa\FilamentConfluence\Models\Concerns;

use Illuminate\Support\Facades\Log;

trait HasConfluenceSpaces
{
    /**
     * Get the Confluence space keys assigned to this user
     */
    public function getConfluenceSpaceKeys(): array
    {
        if (empty($this->confluence_space_keys)) {
            return [];
        }

        try {
            // If it's already an array, return it
            if (is_array($this->confluence_space_keys)) {
                return array_values(array_filter($this->confluence_space_keys));
            }

            // If it's a single string value, wrap in array (backwards compatibility)
            if (is_string($this->confluence_space_keys)) {
                return [$this->confluence_space_keys];
            }

            Log::warning('Invalid confluence_space_keys format for user', [
                'user_id' => $this->id ?? null,
                'confluence_space_keys_value' => $this->confluence_space_keys,
            ]);

            return [];
        } catch (\Exception $e) {
            Log::error('Error processing Confluence space keys', [
                'user_id' => $this->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Check if user has any Confluence space keys assigned
     */
    public function hasConfluenceSpaceKeys(): bool
    {
        return ! empty($this->getConfluenceSpaceKeys());
    }

    /**
     * Get Confluence page assignments for this user
     *
     * @return array<int, array{page_id: string, include_descendants: bool}>
     */
    public function getConfluencePageAssignments(): array
    {
        if (empty($this->confluence_page_assignments)) {
            return [];
        }

        try {
            if (is_array($this->confluence_page_assignments)) {
                // Validate each assignment has required structure
                return array_values(array_filter(
                    $this->confluence_page_assignments,
                    fn ($assignment) => is_array($assignment)
                        && isset($assignment['page_id'])
                        && isset($assignment['include_descendants'])
                        && is_string($assignment['page_id'])
                        && is_bool($assignment['include_descendants'])
                ));
            }

            Log::warning('Invalid confluence_page_assignments format', [
                'user_id' => $this->id ?? null,
                'value' => $this->confluence_page_assignments,
            ]);

            return [];
        } catch (\Exception $e) {
            Log::error('Error processing Confluence page assignments', [
                'user_id' => $this->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Check if user has any page assignments
     */
    public function hasConfluencePageAssignments(): bool
    {
        return ! empty($this->getConfluencePageAssignments());
    }

    /**
     * Get Confluence excluded pages for this user
     *
     * @return array<int, array{page_id: string, exclude_descendants: bool}>
     */
    public function getConfluenceExcludedPages(): array
    {
        if (empty($this->confluence_excluded_pages)) {
            return [];
        }

        try {
            if (is_array($this->confluence_excluded_pages)) {
                // Validate each exclusion has required structure
                return array_values(array_filter(
                    $this->confluence_excluded_pages,
                    fn ($exclusion) => is_array($exclusion)
                        && isset($exclusion['page_id'])
                        && isset($exclusion['exclude_descendants'])
                        && is_string($exclusion['page_id'])
                        && is_bool($exclusion['exclude_descendants'])
                ));
            }

            Log::warning('Invalid confluence_excluded_pages format', [
                'user_id' => $this->id ?? null,
                'value' => $this->confluence_excluded_pages,
            ]);

            return [];
        } catch (\Exception $e) {
            Log::error('Error processing Confluence excluded pages', [
                'user_id' => $this->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Check if user has any page exclusions
     */
    public function hasConfluenceExcludedPages(): bool
    {
        return ! empty($this->getConfluenceExcludedPages());
    }

    /**
     * Check if a specific page is excluded for this user
     */
    public function isConfluencePageExcluded(string|int|float $pageId): bool
    {
        // Convert to string for consistent comparison
        $pageId = (string) $pageId;
        $exclusions = $this->getConfluenceExcludedPages();

        return collect($exclusions)->contains(
            fn ($exclusion) => (string) $exclusion['page_id'] === $pageId
        );
    }

    /**
     * Get all excluded page IDs (flat list)
     */
    public function getConfluenceExcludedPageIds(): array
    {
        return collect($this->getConfluenceExcludedPages())
            ->pluck('page_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Check if user has access to a specific page ID
     * Takes into account both explicit grants and exclusions
     */
    public function hasConfluencePageAccess(string|int|float $pageId): bool
    {
        // Convert to string for consistent comparison
        $pageId = (string) $pageId;

        // First check if page is explicitly excluded
        if ($this->isConfluencePageExcluded($pageId)) {
            return false;
        }

        // Then check if user has explicit access via page assignments
        $assignments = $this->getConfluencePageAssignments();

        return collect($assignments)->contains(
            fn ($assignment) => (string) $assignment['page_id'] === $pageId
        );
    }

    /**
     * Get all assigned page IDs (flat list)
     */
    public function getConfluencePageIds(): array
    {
        return collect($this->getConfluencePageAssignments())
            ->pluck('page_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Check if user has any Confluence access (spaces or pages)
     */
    public function hasConfluenceAccess(): bool
    {
        return $this->hasConfluenceSpaceKeys() || $this->hasConfluencePageAssignments();
    }

    /**
     * Initialize the casts for the trait
     * This should be merged with the model's existing casts
     */
    protected function initializeHasConfluenceSpaces(): void
    {
        $this->mergeCasts([
            'confluence_space_keys' => 'array',
            'confluence_page_assignments' => 'array',
            'confluence_excluded_pages' => 'array',
        ]);
    }
}
