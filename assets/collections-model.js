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
// --- Mutators: validate + transform the tree, mutating `arr` in place and
// returning true iff something changed (so the caller knows to persist). The
// store / persistence / DOM are the caller's concern. opts.canParent(item)
// gates whether a target may hold children (bookmarks: folders only);
// opts.maxDepth caps nesting depth.
function collNest(arr, id, parentId, opts) {
	opts = opts || {};
	var c = collById(arr, id), p = collById(arr, parentId);
	if (!c || !p || id === parentId) return false;
	if (opts.canParent && !opts.canParent(p)) return false;
	if (collIsDescendant(arr, parentId, id)) return false;
	if (collDepth(arr, parentId) + 1 + collHeight(arr, id) > (opts.maxDepth || Infinity) - 1) return false;
	c.parent = parentId;
	return true;
}
function collIndent(arr, id, opts) {
	var prev = collPrevSibling(arr, id);
	return prev ? collNest(arr, id, prev.id, opts) : false;
}
function collOutdent(arr, id) {
	var c = collById(arr, id);
	if (!c || (c.parent || '') === '') return false;
	var p = collById(arr, c.parent);
	c.parent = p ? (p.parent || '') : '';
	return true;
}
function collMove(arr, id, dir) {
	var c = collById(arr, id);
	if (!c) return false;
	var p = c.parent || '';
	var sibs = arr.filter(function (x) { return (x.parent || '') === p; });
	var i = sibs.indexOf(c), j = dir === 'up' ? i - 1 : i + 1;
	if (j < 0 || j >= sibs.length) return false;
	var roots = [], cm = {};
	arr.forEach(function (x) { var pp = x.parent || ''; (pp === '' ? roots : (cm[pp] = cm[pp] || [])).push(x); });
	var lst = p === '' ? roots : cm[p];
	var a = lst.indexOf(c), b = lst.indexOf(sibs[j]);
	var t = lst[a]; lst[a] = lst[b]; lst[b] = t;
	var out = [];
	(function () { function walk(n) { out.push(n); (cm[n.id] || []).forEach(walk); } roots.forEach(walk); })();
	arr.length = 0; Array.prototype.push.apply(arr, out);
	return true;
}
function collPlace(arr, id, targetId, after, opts) {
	opts = opts || {};
	var c = collById(arr, id), t = collById(arr, targetId);
	if (!c || !t || id === targetId) return false;
	if (collIsDescendant(arr, targetId, id)) return false;
	if (collDepth(arr, targetId) + collHeight(arr, id) > (opts.maxDepth || Infinity) - 1) return false;
	var set = collSubtreeSet(arr, id);
	var blk = arr.filter(function (x) { return set[x.id]; });
	var rest = arr.filter(function (x) { return !set[x.id]; });
	c.parent = t.parent || '';
	var ti = collIndexOf(rest, targetId);
	if (ti < 0) { Array.prototype.push.apply(rest, blk); }
	else {
		var at = after ? ti + 1 : ti;
		if (after) { var td = collDepth(rest, targetId); while (at < rest.length && collDepth(rest, rest[at].id) > td) at++; }
		rest.splice.apply(rest, [at, 0].concat(blk));
	}
	arr.length = 0; Array.prototype.push.apply(arr, rest);
	return true;
}
	return { collKey: collKey, collIndexOf: collIndexOf, collById: collById, collChildren: collChildren, collIsParent: collIsParent, collDepth: collDepth, collHeight: collHeight, collIsDescendant: collIsDescendant, collSubtreeSet: collSubtreeSet, collFlatten: collFlatten, collPrevSibling: collPrevSibling, collNest: collNest, collIndent: collIndent, collOutdent: collOutdent, collMove: collMove, collPlace: collPlace };
});
