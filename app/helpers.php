<?php

use Illuminate\Support\Facades\DB;

function is_maintenance_mode() {
    return DB::table('settings')
        ->where('key', 'maintenance_mode')
        ->value('value') === 'on';
}

function whatsapp_inquiry_number(): string
{
    return preg_replace('/\D+/', '', (string) config('services.whatsapp.number', ''));
}

function whatsapp_product_quote_url(string $productName, string $productSlug): string
{
    $number = whatsapp_inquiry_number();
    if ($number === '') {
        return route('webshop.contact_us');
    }

    $productUrl = url('/shop/' . $productSlug);
    $message = "Bonjour EuroDental,\n\nJe souhaite obtenir un devis pour le produit suivant :\n{$productName}\n\nLien : {$productUrl}";

    return 'https://wa.me/' . $number . '?text=' . rawurlencode($message);
}

/**
 * Public URL for a file under storage/app/public (served by laravel-eurodental).
 */
function storage_public_url(?string $imageName): ?string
{
    if ($imageName === null || trim($imageName) === '') {
        return null;
    }

    $relative = 'storage/' . ltrim(str_replace('\\', '/', $imageName), '/');

    return rtrim((string) config('eurodental.asset_base', 'https://eurodental.ma'), '/') . '/' . $relative;
}
