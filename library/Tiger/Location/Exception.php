<?php
// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 WebTigers. Tiger™ and WebTigers™ are trademarks of WebTigers.
/**
 * Tiger_Location_Exception — Location service / adapter error (unsupported capability,
 * misconfiguration, provider failure). The facade catches these and degrades gracefully.
 *
 * @api
 */
class Tiger_Location_Exception extends RuntimeException
{
}
