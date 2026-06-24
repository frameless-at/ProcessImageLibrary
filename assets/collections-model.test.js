/**
 * Unit tests for the pure collections/bookmarks tree model.
 * Run: `node --test assets/collections-model.test.js` (no dependencies).
 */
'use strict';
const test = require('node:test');
const assert = require('node:assert');
const M = require('./collections-model.js');

// Helper: build a fresh array of {id, parent} nodes for each test (the
// mutating helpers like collFlatten reassign .parent on orphans/cycles).
const node = (id, parent) => ({ id: id, parent: parent || '' });

test('collIndexOf / collById', () => {
	const arr = [node('a'), node('b'), node('c')];
	assert.strictEqual(M.collIndexOf(arr, 'b'), 1);
	assert.strictEqual(M.collIndexOf(arr, 'x'), -1);
	assert.strictEqual(M.collById(arr, 'c').id, 'c');
	assert.strictEqual(M.collById(arr, 'x'), null);
});

test('collChildren / collIsParent', () => {
	const arr = [node('a'), node('b', 'a'), node('c', 'a'), node('d', 'b')];
	assert.deepStrictEqual(M.collChildren(arr, 'a').map(c => c.id), ['b', 'c']);
	assert.deepStrictEqual(M.collChildren(arr, 'd').map(c => c.id), []);
	assert.strictEqual(M.collIsParent(arr, 'a'), true);
	assert.strictEqual(M.collIsParent(arr, 'd'), false);
});

test('collDepth', () => {
	const arr = [node('a'), node('b', 'a'), node('c', 'b')];
	assert.strictEqual(M.collDepth(arr, 'a'), 0);
	assert.strictEqual(M.collDepth(arr, 'b'), 1);
	assert.strictEqual(M.collDepth(arr, 'c'), 2);
});

test('collDepth is cycle-safe (capped, no infinite loop)', () => {
	const a = node('a', 'b'), b = node('b', 'a'); // mutual cycle
	const arr = [a, b];
	assert.strictEqual(typeof M.collDepth(arr, 'a'), 'number'); // returns, doesn't hang
});

test('collHeight', () => {
	const arr = [node('a'), node('b', 'a'), node('c', 'b'), node('d')];
	assert.strictEqual(M.collHeight(arr, 'a'), 2); // a > b > c
	assert.strictEqual(M.collHeight(arr, 'b'), 1);
	assert.strictEqual(M.collHeight(arr, 'c'), 0);
	assert.strictEqual(M.collHeight(arr, 'd'), 0); // leaf root
});

test('collIsDescendant', () => {
	const arr = [node('a'), node('b', 'a'), node('c', 'b'), node('x')];
	assert.strictEqual(M.collIsDescendant(arr, 'c', 'a'), true);  // grandchild
	assert.strictEqual(M.collIsDescendant(arr, 'b', 'a'), true);
	assert.strictEqual(M.collIsDescendant(arr, 'a', 'b'), false); // wrong direction
	assert.strictEqual(M.collIsDescendant(arr, 'x', 'a'), false); // unrelated
});

test('collSubtreeSet includes self + all descendants', () => {
	const arr = [node('a'), node('b', 'a'), node('c', 'b'), node('d', 'a'), node('e')];
	const set = M.collSubtreeSet(arr, 'a');
	assert.deepStrictEqual(Object.keys(set).sort(), ['a', 'b', 'c', 'd']);
	assert.strictEqual(set['e'], undefined);
});

test('collFlatten: DFS pre-order, sibling order preserved', () => {
	// source order intentionally not tree-order
	const arr = [node('a'), node('b'), node('a1', 'a'), node('a2', 'a'), node('b1', 'b')];
	const out = M.collFlatten(arr).map(c => c.id);
	assert.deepStrictEqual(out, ['a', 'a1', 'a2', 'b', 'b1']);
});

test('collFlatten: orphan (missing parent) is promoted to root', () => {
	const arr = [node('a'), node('orphan', 'ghost')];
	const out = M.collFlatten(arr);
	assert.deepStrictEqual(out.map(c => c.id), ['a', 'orphan']);
	assert.strictEqual(M.collById(out, 'orphan').parent, ''); // reparented to root
});

test('collFlatten: cycle is broken, every node appears once', () => {
	const arr = [node('a', 'b'), node('b', 'a')]; // pure cycle, no root
	const out = M.collFlatten(arr);
	assert.deepStrictEqual(out.map(c => c.id).sort(), ['a', 'b']);
	assert.strictEqual(out.length, 2); // no duplication, no loss
});

test('collPrevSibling: same parent + depth only', () => {
	const arr = [node('a'), node('a1', 'a'), node('a2', 'a'), node('b')];
	assert.strictEqual(M.collPrevSibling(arr, 'a2').id, 'a1');
	assert.strictEqual(M.collPrevSibling(arr, 'a1'), null); // first child, no prev sibling
	assert.strictEqual(M.collPrevSibling(arr, 'b').id, 'a'); // a and b are root siblings
	assert.strictEqual(M.collPrevSibling(arr, 'a'), null);   // first root
});

test('collKey', () => {
	assert.strictEqual(M.collKey('bm', '7'), 'bm:7');
	assert.strictEqual(M.collKey('coll', 'x'), 'coll:x');
});

// --- Mutators (mutate arr in place, return changed bool) ---

const D3 = { maxDepth: 3 }; // mirrors COLL_MAX_DEPTH = 3 (levels 0,1,2)

test('collNest: nests a leaf under a root', () => {
	const arr = [node('a'), node('b')];
	assert.strictEqual(M.collNest(arr, 'b', 'a', D3), true);
	assert.strictEqual(M.collById(arr, 'b').parent, 'a');
});

test('collNest: rejects self, missing nodes, and cycles', () => {
	const arr = [node('a'), node('b', 'a')];
	assert.strictEqual(M.collNest(arr, 'a', 'a', D3), false);   // self
	assert.strictEqual(M.collNest(arr, 'a', 'x', D3), false);   // missing parent
	assert.strictEqual(M.collNest(arr, 'a', 'b', D3), false);   // a is ancestor of b → cycle
	assert.strictEqual(M.collById(arr, 'a').parent, '');        // unchanged
});

test('collNest: enforces depth cap (3 levels)', () => {
	const arr = [node('a'), node('b', 'a'), node('c')]; // a > b (depth 1)
	assert.strictEqual(M.collNest(arr, 'c', 'b', D3), true);    // depth 2 ok
	const arr2 = [node('a'), node('b', 'a'), node('c', 'b'), node('d')]; // a>b>c (c depth 2)
	assert.strictEqual(M.collNest(arr2, 'd', 'c', D3), false);  // would be depth 3 → rejected
});

test('collNest: canParent gate (bookmark folders only)', () => {
	const arr = [node('a'), node('b')];
	const noChildren = { canParent: () => false, maxDepth: 3 };
	assert.strictEqual(M.collNest(arr, 'b', 'a', noChildren), false);
	assert.strictEqual(M.collById(arr, 'b').parent, '');
});

test('collIndent / collOutdent', () => {
	const arr = [node('a'), node('a1', 'a'), node('a2', 'a')];
	assert.strictEqual(M.collIndent(arr, 'a2', D3), true);      // a2 → child of prev sibling a1
	assert.strictEqual(M.collById(arr, 'a2').parent, 'a1');
	assert.strictEqual(M.collOutdent(arr, 'a2'), true);         // a2 rises to a1's parent (a)
	assert.strictEqual(M.collById(arr, 'a2').parent, 'a');
	assert.strictEqual(M.collOutdent(arr, 'a'), false);         // root can't outdent
});

test('collIndent: no-op for a first child (no prev sibling)', () => {
	const arr = [node('a'), node('a1', 'a')];
	assert.strictEqual(M.collIndent(arr, 'a1', D3), false);
});

test('collMove: reorders siblings (subtrees move together)', () => {
	const arr = [node('a'), node('a1', 'a'), node('a1x', 'a1'), node('a2', 'a')];
	assert.strictEqual(M.collMove(arr, 'a2', 'up'), true);      // swap a2 before a1
	assert.deepStrictEqual(arr.map(c => c.id), ['a', 'a2', 'a1', 'a1x']);
	assert.strictEqual(M.collMove(arr, 'a2', 'up'), false);     // already first child → no-op
});

test('collPlace: moves a subtree to a sibling slot, before/after', () => {
	const arr = [node('a'), node('a1', 'a'), node('b')];
	assert.strictEqual(M.collPlace(arr, 'a1', 'b', true, D3), true); // a1 after b at root
	assert.deepStrictEqual(arr.map(c => c.id), ['a', 'b', 'a1']);
	assert.strictEqual(M.collById(arr, 'a1').parent, '');
});

test('collPlace: rejects dropping a node into its own subtree', () => {
	const arr = [node('a'), node('b', 'a'), node('c', 'b')];
	assert.strictEqual(M.collPlace(arr, 'a', 'c', false, D3), false); // c is inside a's subtree
});
