<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An order that carried the exclusive POS tag from before a supervisor's tag could give a lead to the exclusive CRA:
 * its tag never pulls a lead (ExclusiveLeadTagging::newlyTagged). Crossed off once the tag is off the order, so a
 * supervisor tagging it again counts.
 */
#[Fillable(['pancake_order_id'])]
class ExclusiveOldTag extends Model {}
