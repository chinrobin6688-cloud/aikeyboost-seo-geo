<?php
namespace AIKEY\SeoGeo\Core;

defined( 'ABSPATH' ) || exit;

interface Module_Interface {
    public function id(): string;
    public function register(): void;
}
