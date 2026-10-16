<?php

namespace App\Support;

use App\Models\Bot;

/**
 * Realistic sample conversations, for the database seeder and for simulated test calls (no Vapi key yet).
 * Two sets: a computer-repair receptionist and a restaurant taking phone orders.
 */
class CallScenarios
{
    public static function setFor(Bot $bot): string
    {
        return collect($bot->capture_fields ?? [])->contains('key', 'items') ? 'restaurant' : 'repair';
    }

    /** @return array<int, array{title: string, outcome: string, status: string, summary: string, turns: array, booking: ?array}> */
    public static function all(string $set = 'repair'): array
    {
        return $set === 'restaurant' ? self::restaurant() : self::repair();
    }

    private static function repair(): array
    {
        $hi = ['ai', "Hi, thanks for calling Smart Geeks! I'm Aemal's assistant, how can I help you today?"];

        return [
            [
                'title' => 'Laptop screen repair booking', 'outcome' => 'Booking', 'status' => 'completed',
                'summary' => 'Caller booked a screen repair; name, device and time captured.',
                'turns' => [$hi, ['user', 'Hi, my laptop screen cracked. Can I book a repair?'], ['ai', 'Of course. Could I get your name and the laptop model?'], ['user', '{name}, it\'s a {laptop}.'], ['ai', 'Thanks {first}. We have {day} at 11am or 3pm, which works better?'], ['user', '11 is good.'], ['ai', 'Booked for {day} 11am. A technician will confirm the price after checking it. Can I take your number?'], ['user', 'Sure, {phone}.'], ['ai', 'Perfect, see you then!']],
                'booking' => ['type' => 'booking', 'request' => '{laptop}: cracked screen repair', 'when' => '{day}, 11:00 am', 'details' => ['device' => '{laptop}', 'issue' => 'Cracked screen']],
            ],
            [
                'title' => 'Opening hours on Sunday', 'outcome' => 'Enquiry', 'status' => 'completed',
                'summary' => 'Caller asked about Sunday hours; bot explained the Mon–Sat schedule.',
                'turns' => [$hi, ['user', 'Are you open on Sunday?'], ['ai', "We're closed on Sundays. We're open Monday to Saturday, 10am to 8pm."], ['user', "Okay, I'll come Monday then. Thanks."]],
                'booking' => null,
            ],
            [
                'title' => 'iPhone battery replacement price', 'outcome' => 'Pricing', 'status' => 'completed',
                'summary' => 'Price enquiry answered with a range; caller plans a walk-in.',
                'turns' => [$hi, ['user', 'How much is a battery replacement for an {phoneModel}?'], ['ai', 'It usually costs between Rs 9,000 and Rs 14,000 depending on the battery. A technician confirms the exact price.'], ['user', 'Can I just walk in?'], ['ai', 'Yes, walk-ins are welcome during opening hours. It takes about 45 minutes.']],
                'booking' => null,
            ],
            [
                'title' => 'Caller hung up after the greeting', 'outcome' => 'No outcome', 'status' => 'dropped',
                'summary' => 'Caller disconnected before speaking.',
                'turns' => [$hi],
                'booking' => null,
            ],
            [
                'title' => 'Repair status follow-up', 'outcome' => 'Support', 'status' => 'completed',
                'summary' => 'Status follow-up; callback requested and phone number confirmed.',
                'turns' => [$hi, ['user', "I'm checking on my repair, order {order}."], ['ai', "I've noted order {order}. A technician will call you back today with an update. Can I confirm your number?"], ['user', 'Yes, {phone}.'], ['ai', "Got it. You'll hear from us shortly."]],
                'booking' => null,
            ],
            [
                'title' => 'Hard drive data recovery', 'outcome' => 'Booking', 'status' => 'completed',
                'summary' => 'Data recovery drop-off scheduled.',
                'turns' => [$hi, ['user', 'My external hard drive stopped working, can you recover the data?'], ['ai', 'Yes, we offer data recovery. Could I get your name and a good time to drop it off?'], ['user', '{name}, {day} afternoon.'], ['ai', 'Perfect, {first}. And a phone number in case we need you?'], ['user', '{phone}.'], ['ai', 'Noted, drop-off {day} afternoon. Please bring the cable too.']],
                'booking' => ['type' => 'booking', 'request' => 'External HDD: data recovery drop-off', 'when' => '{day}, afternoon', 'details' => ['device' => 'External hard drive', 'issue' => 'Not detected, data recovery']],
            ],
            [
                'title' => 'Order for a new SSD upgrade', 'outcome' => 'Order', 'status' => 'completed',
                'summary' => 'Caller ordered a 512GB SSD upgrade with installation.',
                'turns' => [$hi, ['user', 'I want to upgrade my laptop to an SSD. Do you have 512GB?'], ['ai', 'Yes, 512GB SSD with installation and data transfer is Rs 16,500. Shall I place the order?'], ['user', 'Yes please. My name is {name}.'], ['ai', 'Thanks {first}. What number can we reach you on?'], ['user', '{phone}.'], ['ai', "Order placed. It'll be ready {day}, we'll text you when it's done."]],
                'booking' => ['type' => 'order', 'request' => '512GB SSD upgrade + data transfer', 'when' => '{day}', 'details' => ['device' => '{laptop}', 'issue' => 'SSD upgrade (Rs 16,500)']],
            ],
            [
                'title' => 'Asked where the shop is', 'outcome' => 'Enquiry', 'status' => 'completed',
                'summary' => 'Caller asked for the address; bot shared location and parking info.',
                'turns' => [$hi, ['user', 'Where exactly is your shop?'], ['ai', "We're at 12 Main Boulevard, Gulberg, Lahore, next to the bank. Free parking is behind the shop."], ['user', 'Great, thanks.']],
                'booking' => null,
            ],
        ];
    }

    private static function restaurant(): array
    {
        $hi = ['ai', 'Assalam o alaikum, Karachi Grill! Would you like to place an order?'];

        return [
            [
                'title' => 'Delivery order: BBQ platter', 'outcome' => 'Order', 'status' => 'completed',
                'summary' => 'Delivery order placed and read back; address and phone captured.',
                'turns' => [$hi, ['user', 'Yes, delivery please. One {platter} and {drinks}.'], ['ai', 'Got it, one {platter} and {drinks}. Anything else?'], ['user', 'Two garlic naan.'], ['ai', 'Total is {total}. Can I have your name, number and address?'], ['user', '{name}, {phone}, {address}.'], ['ai', 'Thank you {first}! Your order will reach you in 35–40 minutes.']],
                'booking' => ['type' => 'order', 'request' => 'Delivery: {platter}, {drinks}, 2× garlic naan ({total})', 'when' => 'ASAP', 'details' => ['order_type' => 'delivery', 'items' => '{platter}, {drinks}, 2× garlic naan', 'address' => '{address}', 'notes' => 'Total {total}']],
            ],
            [
                'title' => 'Pickup order: karahi', 'outcome' => 'Order', 'status' => 'completed',
                'summary' => 'Pickup order placed for 8pm.',
                'turns' => [$hi, ['user', "I'll pick up. Half chicken karahi and four roti."], ['ai', 'Half chicken karahi and four roti for pickup. What time?'], ['user', 'Around 8.'], ['ai', 'Ready at 8pm. Name and number please?'], ['user', '{name}, {phone}.'], ['ai', 'Done! See you at 8, {first}.']],
                'booking' => ['type' => 'order', 'request' => 'Pickup: half chicken karahi, 4× roti', 'when' => 'Today, 8:00 pm', 'details' => ['order_type' => 'pickup', 'items' => 'Half chicken karahi, 4× roti', 'notes' => 'Pickup at 8pm']],
            ],
            [
                'title' => 'Table reservation for Friday', 'outcome' => 'Booking', 'status' => 'completed',
                'summary' => 'Table for 6 reserved on Friday at 9pm.',
                'turns' => [$hi, ['user', 'Can I book a table for six on Friday night?'], ['ai', 'Sure, what time?'], ['user', '9pm. Name {name}, number {phone}.'], ['ai', 'Table for six on Friday at 9pm is reserved, {first}.']],
                'booking' => ['type' => 'booking', 'request' => 'Table for 6', 'when' => 'Friday, 9:00 pm', 'details' => ['order_type' => 'dine-in', 'notes' => '6 guests']],
            ],
            [
                'title' => 'Asked if they deliver to DHA', 'outcome' => 'Enquiry', 'status' => 'completed',
                'summary' => 'Delivery area question answered.',
                'turns' => [$hi, ['user', 'Do you deliver to DHA phase 6?'], ['ai', 'Yes, we deliver to all DHA phases. Delivery is free above Rs 2,000.'], ['user', 'Great, I will order later.']],
                'booking' => null,
            ],
            [
                'title' => 'Caller hung up after the greeting', 'outcome' => 'No outcome', 'status' => 'dropped',
                'summary' => 'Caller disconnected before speaking.',
                'turns' => [$hi],
                'booking' => null,
            ],
        ];
    }

    /** Fill {placeholders} with a random but consistent customer. */
    public static function render(array $scenario, int $seed): array
    {
        $names = ['Bilal Ahmed', 'Sana Khan', 'Usman Tariq', 'Hira Malik', 'Ali Raza', 'Ayesha Noor', 'Hamza Sheikh', 'Fatima Iqbal', 'Zain Abbas', 'Maryam Butt', 'Omar Farooq', 'Iqra Javed'];
        $laptops = ['Dell XPS 13', 'HP Pavilion 15', 'Lenovo ThinkPad T14', 'MacBook Air M1', 'Asus VivoBook'];
        $phones = ['iPhone 12', 'iPhone 13', 'Samsung S21', 'iPhone 11'];
        $days = ['Saturday', 'Monday', 'Tuesday', 'Thursday', 'tomorrow'];
        $platters = ['Grill platter (large)', 'Mixed BBQ platter', 'Seekh kebab platter', 'Chicken tikka platter'];
        $drinks = ['2× mint margarita', '1.5L Coke', '3× fresh lime', '2× lassi'];
        $addresses = ['House 14, Street 3, DHA Phase 6', 'Flat 7B, Clifton Block 5', 'House 221, Gulshan-e-Iqbal 13D', 'Office 4, Shahrah-e-Faisal'];

        $name = $names[$seed % count($names)];
        $vars = [
            '{name}' => $name,
            '{first}' => explode(' ', $name)[0],
            '{laptop}' => $laptops[$seed % count($laptops)],
            '{phoneModel}' => $phones[$seed % count($phones)],
            '{day}' => $days[$seed % count($days)],
            '{phone}' => sprintf('03%02d %07d', ($seed * 7) % 50, (1234567 + $seed * 7919) % 10000000),
            '{order}' => (string) (4000 + $seed * 13 % 900),
            '{platter}' => $platters[$seed % count($platters)],
            '{drinks}' => $drinks[$seed % count($drinks)],
            '{address}' => $addresses[$seed % count($addresses)],
            '{total}' => 'Rs '.number_format(2400 + ($seed * 370) % 3200),
        ];
        $fill = fn ($s) => strtr($s, $vars);
        $b = $scenario['booking'];

        return [
            'title' => $scenario['title'],
            'outcome' => $scenario['outcome'],
            'status' => $scenario['status'],
            'summary' => $scenario['summary'],
            'turns' => array_map(fn ($t) => [$t[0], $fill($t[1])], $scenario['turns']),
            'booking' => $b ? [
                'type' => $b['type'],
                'customerName' => $name,
                'phone' => $vars['{phone}'],
                'request' => $fill($b['request']),
                'preferredTime' => ucfirst($fill($b['when'])),
                'details' => array_map($fill, $b['details'] ?? []),
            ] : null,
        ];
    }

    /** Build a Vapi-shaped end-of-call-report, for simulated test calls. */
    public static function endOfCallReport(Bot $bot, int $seconds, bool $isTest, int $seed): array
    {
        $all = self::all(self::setFor($bot));
        $s = self::render($all[$seed % count($all)], $seed);
        $start = now()->subSeconds($seconds);

        return [
            'type' => 'end-of-call-report',
            'endedReason' => $s['status'] === 'dropped' ? 'customer-ended-call' : 'assistant-ended-call',
            'call' => ['id' => 'sim_call_'.uniqid(), 'metadata' => ['botId' => $bot->id, 'isTest' => $isTest]],
            'startedAt' => $start->toIso8601String(),
            'endedAt' => now()->toIso8601String(),
            'durationSeconds' => $seconds,
            'analysis' => [
                'summary' => $s['summary'],
                'structuredData' => ['title' => $s['title'], 'outcome' => $s['outcome'], 'booking' => $s['booking']],
            ],
            'artifact' => ['messages' => array_map(fn ($t) => ['role' => $t[0] === 'ai' ? 'assistant' : 'user', 'message' => $t[1]], $s['turns'])],
        ];
    }
}
