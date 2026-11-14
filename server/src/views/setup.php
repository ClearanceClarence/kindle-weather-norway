<?php

declare(strict_types=1);

/*
 * Setup notice: shown until a location has been set, on the admin page or
 * in config.php.
 */

function render_setup(array $req, array $config): string
{
    $c = new Canvas($config['width'], $config['height'], $config['font_dir']);
    $mid = (LEFT + RIGHT) / 2;
    $c->icon('sun', $mid - 130, 360, 260, 1.4);
    $c->text(t('Værvarsel'), $mid, 760, 110, 'Bold', INK, 'center');
    $c->text(t('Stedet er ikke satt opp ennå.'), $mid, 890, 44, 'Medium', GRAY, 'center');
    $c->text(t('Åpne /admin.php på nettstedet'), $mid, 990, 44, 'Bold', INK, 'center');
    $c->text(t('i en nettleser for å komme i gang.'), $mid, 1050, 44, 'Medium', GRAY, 'center');
    return $c->png();
}
