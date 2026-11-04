<?php

namespace App\Jobs;

/**
 * @deprecated Kept so jobs queued before the rename to VoidMemberVotes still run; delete once `jobs` and `failed_jobs`
 *             hold no such payload (`SELECT count(*) FROM jobs WHERE payload LIKE '%VoidDeletedMemberVotes%'`, same on `failed_jobs`).
 */
class VoidDeletedMemberVotes extends VoidMemberVotes {}
