<?php
declare(strict_types=1);
// User direction, 2026-10-31: half-speed combat presentation. Mechanical turns
// keep their own clock; visual expirations and movement locks use this helper.
function applicationCombatAnimationDuration(int|float $milliseconds): int { return (int)ceil($milliseconds/.5); }
