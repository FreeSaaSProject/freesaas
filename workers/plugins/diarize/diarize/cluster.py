"""Agglomerative clustering of L2-normalized speaker embeddings (cosine)."""

from __future__ import annotations

from typing import List, Sequence, Tuple

import numpy as np


def cosine_distance(a: np.ndarray, b: np.ndarray) -> float:
	if a.size == 0 or b.size == 0 or a.shape != b.shape:
		return 1.0
	return float(max(0.0, 1.0 - float(np.dot(a, b))))


def _centroid(vecs: Sequence[np.ndarray]) -> np.ndarray:
	stack = np.stack([np.asarray(v, dtype=np.float32).reshape(-1) for v in vecs], axis=0)
	cent = np.mean(stack, axis=0)
	norm = float(np.linalg.norm(cent))
	if norm > 1e-8:
		cent = cent / norm
	return cent.astype(np.float32)


def cluster_embeddings(
	embeddings: Sequence[np.ndarray],
	*,
	threshold: float = 0.55,
	max_speakers: int = 4,
) -> Tuple[List[int], List[np.ndarray]]:
	"""
	Average-linkage agglomerative clustering on cosine distance.
	Returns (labels[i] for each embedding, centroids ordered by label).
	"""
	valid_idx = [i for i, e in enumerate(embeddings) if e is not None and getattr(e, "size", 0) > 0]
	n = len(embeddings)
	labels = [-1] * n
	if not valid_idx:
		return labels, []

	vecs = [np.asarray(embeddings[i], dtype=np.float32).reshape(-1) for i in valid_idx]
	clusters: List[List[int]] = [[i] for i in range(len(vecs))]

	while len(clusters) > 1:
		best_pair = None
		best_d = 2.0
		cents = [_centroid([vecs[j] for j in c]) for c in clusters]
		for i in range(len(clusters)):
			for j in range(i + 1, len(clusters)):
				d = cosine_distance(cents[i], cents[j])
				if d < best_d:
					best_d = d
					best_pair = (i, j)
		if best_pair is None:
			break
		# Stop if next merge is too far, or we would go below max_speakers with a weak merge
		if best_d > threshold:
			break
		if len(clusters) <= max(1, int(max_speakers)) and best_d > threshold * 0.9:
			break
		i, j = best_pair
		if i > j:
			i, j = j, i
		clusters[i] = clusters[i] + clusters[j]
		del clusters[j]

	# Cap: if still too many, force-merge closest until max_speakers
	while len(clusters) > max(1, int(max_speakers)):
		cents = [_centroid([vecs[j] for j in c]) for c in clusters]
		best_pair = None
		best_d = 2.0
		for i in range(len(clusters)):
			for j in range(i + 1, len(clusters)):
				d = cosine_distance(cents[i], cents[j])
				if d < best_d:
					best_d = d
					best_pair = (i, j)
		if best_pair is None:
			break
		i, j = best_pair
		if i > j:
			i, j = j, i
		clusters[i] = clusters[i] + clusters[j]
		del clusters[j]

	order = sorted(range(len(clusters)), key=lambda c: min(clusters[c]))
	final_centroids = [_centroid([vecs[j] for j in clusters[old]]) for old in order]
	remap = {old: new for new, old in enumerate(order)}
	for old, members in enumerate(clusters):
		lab = remap[old]
		for mi in members:
			labels[valid_idx[mi]] = lab
	return labels, final_centroids
