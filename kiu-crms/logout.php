<?php
require __DIR__ . '/app/bootstrap.php';
require_post();
logout_user();
flash('success', 'You are logged out.');
redirect('login.php');
