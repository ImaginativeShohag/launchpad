<?php

namespace App\Events;

/** A launch day was picked or changed. The listener re-reads the day from the product. */
final class ProductScheduled extends ProductEvent {}
