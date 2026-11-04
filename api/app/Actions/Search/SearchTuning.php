<?php

namespace App\Actions\Search;

/** The numbers of the search feature, in one place: limits of a query and the weights of the relevance score. */
final class SearchTuning
{
    /** Characters (mb_strlen) of `q`. */
    public const MAX_QUERY_LENGTH = 100;

    public const MIN_TOKEN_LENGTH = 2;

    public const MAX_TOKENS = 8;

    public const MAX_TOKEN_LENGTH = 50;

    /** The trigram arm only runs from this many characters of the joined tokens (a shorter set of trigrams is nearly empty). */
    public const MIN_TYPO_LENGTH = 3;

    /** `pg_trgm.word_similarity_threshold` for the transaction. Transpositions score below it (a documented limit). */
    public const TRIGRAM_THRESHOLD = 0.5;

    /** score = TIER_WEIGHT * (full-text hit) + RANK_WEIGHT * ts_rank_cd + word_similarity + exact name: any full-text hit beats any typo-only hit. */
    public const TIER_WEIGHT = 2;

    public const RANK_WEIGHT = 2;

    /** ts_rank_cd normalisation 32: rank / (rank + 1), so it stays below 1. */
    public const TS_RANK_NORMALIZATION = 32;

    public const SCORE_DECIMALS = 4;
}
