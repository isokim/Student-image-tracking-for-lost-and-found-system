<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hybrid match weights
    |--------------------------------------------------------------------------
    |
    | How much each signal contributes to the overall score. They are here,
    | in one place, rather than scattered through the matching code, so
    | they can be tuned without touching logic - and so anyone reading the
    | system can see the whole policy at a glance.
    |
    | Image similarity dominates because it is the hardest signal to fake
    | and the least dependent on how well a student writes a description.
    | Category, colour, location and time are weak individually - plenty of
    | black bags are lost near the library - so each is small, and they
    | matter mainly in combination.
    |
    | Brand used to be a 0.05 component here. It was dropped along with the
    | form field, and its weight was split across colour and location, the
    | two signals it was most redundant with.
    |
    | Must sum to 1.0; MatchScoringService asserts this at runtime rather
    | than silently producing scores on a different scale.
    |
    */

    'weights' => [
        'image' => 0.50,
        'text' => 0.20,
        'category' => 0.10,
        'colour' => 0.075,
        'location' => 0.075,
        'time' => 0.05,
    ],

    /*
    |--------------------------------------------------------------------------
    | Re-weighting when there is no image
    |--------------------------------------------------------------------------
    |
    | Plenty of reports have no photo. If the image weight were simply
    | dropped, every photoless pair would score at most 0.50 and look
    | far worse than it is.
    |
    | Instead the image component is removed and the remaining weights are
    | scaled up proportionally, so the score still runs 0..1 and stays
    | comparable with matches that did have a photo.
    |
    */

    'redistribute_image_weight_when_missing' => true,

    /*
    |--------------------------------------------------------------------------
    | Thresholds
    |--------------------------------------------------------------------------
    |
    | minimum_score  - below this, a pair is not recorded at all. Keeps the
    |                  student's match list short and believable instead of
    |                  listing every black object on campus.
    | likely_score   - at or above this the UI says "Likely match" rather
    |                  than "Possible match". Note there is deliberately no
    |                  threshold that claims certainty: a human approving a
    |                  claim is the only thing that confirms ownership.
    |
    */

    'thresholds' => [
        'minimum_score' => 0.45,
        'likely_score' => 0.75,
    ],

    /*
    |--------------------------------------------------------------------------
    | Time relevance
    |--------------------------------------------------------------------------
    |
    | An item is usually found within a few days of being lost. The time
    | score decays from 1.0 (found the same day it was lost) to 0.0 at
    | the window below.
    |
    | A found date *before* the lost date scores 0: you cannot find
    | something before it was lost.
    |
    */

    'time_window_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Candidate limits
    |--------------------------------------------------------------------------
    |
    | How many candidates to pull from FAISS before scoring, and how many
    | matches to keep per item. Scoring is cheap; asking the vector index
    | for thousands of neighbours is not.
    |
    */

    'candidates' => [
        'from_vector_search' => 50,
        'max_stored_per_item' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring version
    |--------------------------------------------------------------------------
    |
    | Stamped onto every item_matches row. Bump it when the weights or the
    | algorithm change, so old scores stay interpretable instead of being
    | silently compared against new ones.
    |
    */

    'version' => 'v1',

];
