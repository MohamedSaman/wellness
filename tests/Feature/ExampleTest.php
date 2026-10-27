<?php

test('the home page sends a guest to sign in', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});
