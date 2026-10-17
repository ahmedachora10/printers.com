<?php

it('sends the root url to the dashboard', function () {
    $this->get('/')->assertRedirect('/dashboard');
});
