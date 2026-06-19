/**
 * Pure tree model for the bookmarks / collections manager — array in, data
 * out: no DOM, no shared state, no persistence. Lives in its own file so it
 * can be unit-tested under `node --test` (collections-model.test.js); the
 * admin IIFE (ProcessImageLibrary.js) aliases these via window.MLCollectionsModel.
 */
(function (root, factory) {
	var api = factory();
	if (typeof module !== 'undefined' && module.exports) module.exports = api;
	if (root) root.MLCollectionsModel = api;
})(typeof self !== 'undefined' ? self : (typeof globalThis !== 'undefined' ? globalThis : this), function () {
	'use strict';
function collKey(kind, id) { return kind + ':' + id; }
function collIndexOf(arr, id) {
	for (var i = 0; i < arr.length; i++) if (arr[i] && arr[i].id === id) return i;
	return -1;
}
function collById(arr, id) { var i = collIndexOf(arr, id); return i < 0 ? null : arr[i]; }
function collChildren(arr, id) { return arr.filter(function (c) { return c && (c.parent || '') === id; }); }
function collIsParent(arr, id) { return arr.some(function (c) { return c && (c.parent || '') === id; }); }
function collDepth(arr, id) {
	var d = 0, c = collById(arr, id), g = 0;
	while (c && (c.parent || '') !== '' && g++ < 64) { d++; c = collById(arr, c.parent); }
	return d;
}
function collHeight(arr, id) {
	var ch = collChildren(arr, id);
	if (!ch.length) return 0;
	var m = 0;
	ch.forEach(function (c) { m = Math.max(m, collHeight(arr, c.id)); });
	return 1 + m;
}
function collIsDescendant(arr, id, ofId) {
	var c = collById(arr, id), g = 0;
	while (c && (c.parent || '') !== '' && g++ < 64) { if (c.parent === ofId) return true; c = collById(arr, c.parent); }
	return false;
}
function collSubtreeSet(arr, id) {
	var s = {}; s[id] = true; var changed = true;
	while (changed) { changed = false; arr.forEach(function (c) { if (c && c.parent && s[c.parent] && !s[c.id]) { s[c.id] = true; changed = true; } }); }
	return s;
}
// DFS flatten by parent, preserving sibling array order; promote orphans / cycles.
function collFlatten(arr) {
	var byId = {};
	arr.forEach(function (c) { if (c && c.id) byId[c.id] = c; });
	arr.forEach(function (c) { if (c && (c.parent || '') !== '' && !byId[c.parent]) c.parent = ''; });
	var roots = [], cm = {};
	arr.forEach(function (c) { if (!c) return; var p = c.parent || ''; (p === '' ? roots : (cm[p] = cm[p] || [])).push(c); });
	var out = [], seen = {};
	function walk(n) { if (seen[n.id]) return; seen[n.id] = true; out.push(n); (cm[n.id] || []).forEach(walk); }
	roots.forEach(walk);
	arr.forEach(function (c) { if (c && !seen[c.id]) { c.parent = ''; seen[c.id] = true; out.push(c); } });
	return out;
}
function collPrevSibling(arr, id) {
	var i = collIndexOf(arr, id);
	if (i <= 0) return null;
	var c = arr[i], d = collDepth(arr, id);
	for (var j = i - 1; j >= 0; j--) {
		var dj = collDepth(arr, arr[j].id);
		if (dj < d) break;
		if (dj === d && (arr[j].parent || '') === (c.parent || '')) return arr[j];
	}
	return null;
}
	return { collKey: collKey, collIndexOf: collIndexOf, collById: collById, collChildren: collChildren, collIsParent: collIsParent, collDepth: collDepth, collHeight: collHeight, collIsDescendant: collIsDescendant, collSubtreeSet: collSubtreeSet, collFlatten: collFlatten, collPrevSibling: collPrevSibling };
});
