<?php namespace ProcessWire;

/**
 * Process Media Library
 *
 * Central table view of all images across all pages and image fields.
 * Editors can filter and inline-edit image metadata (description, tags,
 * custom subfields) without navigating per page.
 *
 * Phase 1 scope: read-pipeline, filters, sort, pagination, inline-edit,
 * per-user column config. See MediaLibrary-Konzept.md.
 */
class ProcessMediaLibrary extends Process {

	const ADMIN_PAGE_NAME = 'media-library';
	const PERMISSION_NAME = 'media-library-access';
	const CACHE_PREFIX = 'media-library-';

	/**
	 * Render the main media library admin page.
	 *
	 * Server-renders the filter bar, table shell, and first page slice.
	 * JS hydrates for interactive filtering, sorting and inline-edit.
	 */
	public function ___execute() {
		return '<p>Media Library — Phase 1 skeleton. Implementation pending.</p>';
	}

	/**
	 * AJAX endpoint: returns paginated rows + total count + filter summaries as JSON.
	 */
	public function ___executeData() {
		$this->wire('config')->ajax = true;
		header('Content-Type: application/json');
		return json_encode(['rows' => [], 'total' => 0]);
	}

	/**
	 * AJAX endpoint: validates and persists a single cell change.
	 *
	 * Expects POST: { pageId, fieldName, basename, subfield, value }
	 * Returns JSON: { ok, value, error? }
	 */
	public function ___executeSave() {
		$this->wire('config')->ajax = true;
		header('Content-Type: application/json');
		return json_encode(['ok' => false, 'error' => 'not implemented']);
	}

	/**
	 * Install: create admin page under Setup and the access permission.
	 */
	public function ___install() {
		parent::___install();

		$permissions = $this->wire('permissions');
		if (!$permissions->get(self::PERMISSION_NAME)->id) {
			$p = $permissions->add(self::PERMISSION_NAME);
			$p->title = $this->_('Access the Media Library admin page');
			$p->save();
			$this->message("Created permission: " . self::PERMISSION_NAME);
		}
	}

	/**
	 * Uninstall: remove admin page and clear module cache entries.
	 *
	 * Leaves user-meta (mediaLibraryColumns) and the permission in place —
	 * those are user-owned state, not module state.
	 */
	public function ___uninstall() {
		$cache = $this->wire('cache');
		$cache->deleteFor($this, '*');

		parent::___uninstall();
	}
}
