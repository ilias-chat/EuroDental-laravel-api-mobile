<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Order;

class NewWebshopOrderMail extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $clientName;
    public $orderUrl;
    public $totalFormatted;
    public $orderDate;

    public function __construct(Order $order)
    {
        $order->load(['client']);
        $this->order = $order;
        $this->clientName = $order->client
            ? trim($order->client->first_name . ' ' . $order->client->last_name)
            : 'Client';
        $this->orderUrl = route('admin.orders.show', ['order' => $order->id]);
        $this->totalFormatted = number_format((float) $order->total_with_tax ?? $order->total_price, 2, ',', ' ') . ' €';
        $this->orderDate = $order->created_at->format('d/m/Y H:i');
    }

    public function build()
    {
        return $this->subject('Nouvelle commande webshop - ' . $this->clientName)
            ->view('emails.new_webshop_order')
            ->with([
                'order' => $this->order,
                'clientName' => $this->clientName,
                'orderUrl' => $this->orderUrl,
                'totalFormatted' => $this->totalFormatted,
                'orderDate' => $this->orderDate,
                'appUrl' => config('app.url'),
            ]);
    }
}
