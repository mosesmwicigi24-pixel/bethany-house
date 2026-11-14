<?php

namespace App\Support;

/**
 * The first content of the staff pages (owner, 2026-11-14: "fill in the first
 * template in every tab, so that it starts from there").
 *
 * Everything here is plain text (the pages render bodies with line breaks
 * kept, no markdown) and is loaded once by `people:starter-content`, after
 * which it is ordinary content the team edits in the Hub. Facts are only those
 * the business already states: the shop on Moi Avenue, the storefront's
 * delivery promise, the payment routes the Hub offers, the leave entitlements
 * the Hub holds. Policies the owner has not written down (returns, deposits,
 * engraving) are deliberately absent.
 */
final class StarterContent
{
    public const CELEBRATION_KEY = 'hub-launch-2026';

    /** Handbook articles. `sign` = staff are asked to sign it; `must_read` = it also appears as a must-read update. */
    public static function articles(): array
    {
        return [
            [
                'title' => 'Using the hub every day',
                'category' => 'Start here',
                'must_read' => true,
                'sign' => false,
                'body' => <<<'TXT'
The hub is where your working day lives. Everything you need is under My Workday in the menu.

Today: your day at a glance. What is due, what is waiting for you, and your hours this week.
Updates: news from the shop, events, recognitions and celebrations. Read the ones marked must-read.
Checklists: open and close the shop with the checklist, and report an incident or damage the moment it happens.
Handbook: how we do things at Bethany House. If you are unsure, look here first.
Training: short courses with a quiz at the end. Required ones stay on your Today page until you pass.
To sign: policies you are asked to read and sign on your phone.
To-dos: things to do, from you or from your manager, with a due date.
Colleagues: everyone at Bethany House and how to reach them. You choose what others see about you.
Clock in: clock in when you arrive at the shop and out when you leave. It checks that you are at the shop.
My shifts: the days and times you are rostered.
My leave: your leave balances, and where you ask for leave.
My hours: your hours and days worked. If a day is wrong, tap "fix?" on that day and say what happened.

If something in the hub looks wrong, tell your manager the same day. A wrong number found today is easy to fix; found next month, it is not.
TXT,
            ],
            [
                'title' => 'Who we are and what we sell',
                'category' => 'About us',
                'must_read' => false,
                'sign' => false,
                'body' => <<<'TXT'
Bethany House serves churches and the people who lead them. Our shop is on Moi Avenue, Nairobi, and we serve customers across Kenya, East Africa and worldwide.

What we sell:
- Clergy apparel: vestments, cassocks, stoles, albs and robes, many of them made to measure by our own tailors.
- Communion elements: chalices, patens, communion trays and the vessels of the altar.
- Gifts and accessories for ordinations, anniversaries, weddings and new sanctuaries.
- Church essentials for the parish and the sanctuary.

How customers reach us:
- In the shop (sales at the till).
- On our website, bethanyhouse.co.ke.
- On WhatsApp and chat, where Neema, our assistant, answers first and hands over to us when a person is needed.
- Parishes and dioceses ask for quotations; we aim to send a quotation within a day.

Every sale, quotation and payment goes on the hub when it happens, whichever way the customer came.
TXT,
            ],
            [
                'title' => 'Serving parishes and dioceses',
                'category' => 'Serving customers',
                'must_read' => false,
                'sign' => false,
                'body' => <<<'TXT'
Many of our customers buy for a whole parish or diocese. They often need a quotation before they can pay.

1. Write the quotation on the hub (Sales > Quotations), not on paper or in a chat message. The hub keeps the prices, the currency and the history together.
2. Send it the same day. Our promise to parish and diocese accounts is a quotation within a day.
3. Note who asked (name, role, parish) and how they want to be reached.
4. When they confirm, turn the quotation into an order on the hub. Do not retype it.
5. For made-to-measure items, take the measurements before you promise a ready date.

Be patient and courteous: a priest or a parish secretary is often buying with the congregation's money and must account for every shilling. A clear quotation and a proper receipt help them do that.
TXT,
            ],
            [
                'title' => 'Taking payment',
                'category' => 'Money',
                'must_read' => true,
                'sign' => true,
                'body' => <<<'TXT'
Money is recorded on the hub at the moment it is received. Never later, never on paper first.

M-Pesa
- Check the payment on the shop's M-Pesa line, not on the customer's phone. A screenshot from the customer is not proof.
- Record the M-Pesa code on the sale.

Cash
- Count it in front of the customer and give a receipt from the hub.
- Cash goes in the till, never in a pocket or a bag, not even for a moment.

Card
- Only through the hub's card payment. Keep the slip with the day's records.

Bank transfer (I&M Bank)
- An order is released only when the money shows in the account, or when a manager confirms it.

Customers abroad
- The hub bills them in their currency (Kenya in KES, Zambia in ZMW, South Africa in ZAR, others in USD) and offers Mukuru, Western Union or MoneyGram, or a bank transfer to I&M. They upload their proof of payment, and the money is confirmed before the order is released.

Never
- Never use a personal M-Pesa number or account for a customer's money.
- Never cover a shortage or keep an overage. Report the difference. An honest difference is a problem we fix together; a hidden one is a breach of trust.
TXT,
            ],
            [
                'title' => 'Your till, from float to close',
                'category' => 'Money',
                'must_read' => true,
                'sign' => false,
                'body' => <<<'TXT'
Opening
- Count the opening float and enter it on the opening checklist.
- Make sure the till, the receipt printer and the M-Pesa phone are working before the first customer.

During the day
- Every sale goes through the till, including sales to staff and to people you know.
- If a customer pays part now and the rest later, record the part payment. The hub tracks what is still owed.

Closing
- Count the cash, close the till on the hub, and complete the closing checklist before you leave.
- If the count does not match, record the difference and tell your manager. Do not adjust the cash to make it match.

A finalized till cannot be edited. A mistake found later is corrected by a manager with a recorded reason, so the history always shows what really happened.
TXT,
            ],
            [
                'title' => 'WhatsApp, chat and web orders',
                'category' => 'Serving customers',
                'must_read' => false,
                'sign' => false,
                'body' => <<<'TXT'
Neema, our assistant, answers customers first on WhatsApp and chat. She knows our products, prices and delivery, and she hands a conversation to us when a person is needed.

When a chat is flagged for you
- Read the short summary under the flag, then reply as soon as you can.
- Do not promise what the hub cannot do. If you are unsure of a price, a stock level or a date, check the hub or ask.

Orders from the web and chat
- Check the day's web and chat orders when you open the shop, and again during the day.
- An order is confirmed when it is paid, or when a manager approves it on account.

Delivery (what the website promises)
- Orders placed before 2 PM can be delivered the same day within Nairobi.
- Delivery is free in the Nairobi CBD on orders over KES 10,000.
- We deliver across Kenya, East Africa and worldwide.
TXT,
            ],
            [
                'title' => 'Attendance, leave and your hours',
                'category' => 'People',
                'must_read' => false,
                'sign' => false,
                'body' => <<<'TXT'
Clocking in and out
- Clock in when you arrive at the shop and out when you leave, on My Workday > Clock in. Your phone's location is read only while that screen is open.
- Forgot? Do not clock in later from somewhere else. Tap "fix?" on that day under My hours and say what happened.

Leave
- Annual leave: 17.5 days a year, earned monthly.
- Sick leave: 14 days. Maternity leave: 90 days. Paternity leave: 14 days. Compassionate leave: 5 days.
- Days are working days; public holidays do not count.
- Ask on My leave as early as you can. Your request is decided by a manager, and you will see the answer there.

Your file
- Keep your phone number and next of kin up to date with HR. You choose what colleagues see about you on the Colleagues page.
TXT,
            ],
            [
                'title' => 'How we work together',
                'category' => 'People',
                'must_read' => false,
                'sign' => true,
                'body' => <<<'TXT'
At Bethany House we serve the Church. The way we work should honour that.

- Honesty: with money, with time and with customers. If you make a mistake, say so. We fix mistakes; we do not hide them.
- Respect: every customer, from a parish secretary to a bishop, and every colleague, is treated with courtesy.
- Care for the goods: vestments, altar ware and sacred items are handled with clean hands and put back where they belong.
- Customer details stay private. Phone numbers, addresses and orders are for serving the customer, never for anything else.
- Time: arrive for your shift, clock in, and tell your manager before, not after, if you will be late or absent.
- Ask: if you do not know, ask a colleague or look in this handbook. Nobody is expected to know everything on day one.
TXT,
            ],
        ];
    }

    /** Training courses (plain-text lessons; quiz answers are option indexes). */
    public static function courses(): array
    {
        return [
            [
                'title' => 'Hub basics: your first week',
                'description' => 'How to clock in, open and close the shop, and find what you need on the hub. About 10 minutes.',
                'required' => true,
                'pass_mark' => 80,
                'lessons' => [
                    ['title' => 'Your day starts with Clock in', 'body' => "When you arrive at the shop, open My Workday > Clock in and tap Clock in. It checks that you are at the shop.\n\nWhen you leave, clock out. Your hours appear under My hours.\n\nIf a day is wrong (you forgot, or your phone died), tap \"fix?\" on that day under My hours and say what happened. Do not clock in later from somewhere else."],
                    ['title' => 'Open and close with the checklist', 'body' => "Every working day the shop is opened and closed with a checklist on My Workday > Checklists.\n\nThe opening checklist records the opening float and that the till and the M-Pesa phone are ready. The closing checklist records the cash count, that the till is closed on the hub, and that the shop is locked.\n\nA checklist not done on time shows as missed and your manager is told."],
                    ['title' => 'Updates, handbook and signing', 'body' => "Updates is the shop's notice board. Some posts are marked must-read: open them and mark them read.\n\nThe handbook explains how we do things. Some policies you are asked to sign: they appear under To sign, and you read and sign them on your phone."],
                    ['title' => 'To-dos and leave', 'body' => "To-dos are tasks with a due date, from you or your manager. Mark them done when they are done.\n\nAsk for leave on My leave. You see your balances there, and the decision when it is made."],
                ],
                'questions' => [
                    ['question' => 'You forgot to clock in this morning. What do you do?', 'options' => ['Clock in now and say nothing', 'Tap "fix?" on that day under My hours and say what happened', 'Ask a colleague to clock in for you'], 'answer' => 1],
                    ['question' => 'Where do you record the opening float?', 'options' => ['On the opening checklist', 'In a notebook', 'In a WhatsApp message to the manager'], 'answer' => 0],
                    ['question' => 'A post is marked must-read. What should you do?', 'options' => ['Ignore it if it looks long', 'Open it and mark it read', 'Forward it to a colleague'], 'answer' => 1],
                    ['question' => 'Where do you ask for leave?', 'options' => ['On My leave', 'By phoning a colleague', 'On the closing checklist'], 'answer' => 0],
                    ['question' => 'What happens if the closing checklist is not done on time?', 'options' => ['Nothing', 'It shows as missed and your manager is told', 'The till closes itself'], 'answer' => 1],
                ],
            ],
            [
                'title' => 'Taking payment the Bethany House way',
                'description' => 'M-Pesa, cash, card, bank and payments from abroad: how each is checked and recorded. About 10 minutes.',
                'required' => true,
                'pass_mark' => 80,
                'lessons' => [
                    ['title' => 'M-Pesa', 'body' => "Check the payment on the shop's M-Pesa line, never on the customer's phone. A screenshot is not proof.\n\nRecord the M-Pesa code on the sale on the hub."],
                    ['title' => 'Cash and card', 'body' => "Count cash in front of the customer, put it in the till, and give a receipt from the hub.\n\nCard payments go only through the hub's card payment."],
                    ['title' => 'Bank transfers and customers abroad', 'body' => "A bank transfer (I&M Bank) releases an order only when the money shows in the account, or a manager confirms it.\n\nCustomers abroad are billed in their own currency (Kenya KES, Zambia ZMW, South Africa ZAR, others USD). They pay by Mukuru, Western Union, MoneyGram or bank transfer, and upload their proof of payment."],
                    ['title' => 'When the count does not match', 'body' => "Record the difference and tell your manager. Never cover a shortage from your own pocket and never keep an overage.\n\nA finalized till is not edited; a manager corrects it with a recorded reason."],
                ],
                'questions' => [
                    ['question' => 'A customer shows you a screenshot of an M-Pesa payment. Is that enough?', 'options' => ['Yes', 'No: check the payment on the shop\'s M-Pesa line'], 'answer' => 1],
                    ['question' => 'Your closing cash count is KES 200 short. What do you do?', 'options' => ['Add KES 200 of your own', 'Record the difference and tell your manager', 'Leave it for tomorrow'], 'answer' => 1],
                    ['question' => 'A customer in Zambia orders. In which currency are they billed?', 'options' => ['KES', 'ZMW', 'USD'], 'answer' => 1],
                    ['question' => 'When is an order paid by bank transfer released?', 'options' => ['When the customer says they have paid', 'When the money shows in the account, or a manager confirms it'], 'answer' => 1],
                    ['question' => 'May a customer pay into your personal M-Pesa number?', 'options' => ['Yes, if you forward it later', 'Never'], 'answer' => 1],
                ],
            ],
            [
                'title' => 'Know our range',
                'description' => 'The four families of what we sell, and how customers reach us. About 5 minutes.',
                'required' => false,
                'pass_mark' => 75,
                'lessons' => [
                    ['title' => 'What we sell', 'body' => "Clergy apparel (vestments, cassocks, stoles, albs, robes), many made to measure by our tailors.\nCommunion elements (chalices, patens, communion trays).\nGifts and accessories for ordinations, anniversaries, weddings and new sanctuaries.\nChurch essentials for the parish and the sanctuary."],
                    ['title' => 'How customers reach us', 'body' => "In the shop on Moi Avenue, Nairobi. On bethanyhouse.co.ke. On WhatsApp and chat, where Neema answers first. And by quotation, for parishes and dioceses, which we aim to send within a day."],
                ],
                'questions' => [
                    ['question' => 'Which of these is a communion element we sell?', 'options' => ['A chalice', 'A hymn book', 'A pew'], 'answer' => 0],
                    ['question' => 'Who answers customers first on WhatsApp?', 'options' => ['The manager', 'Neema, our assistant', 'Nobody'], 'answer' => 1],
                    ['question' => 'How soon do we aim to send a parish its quotation?', 'options' => ['Within a day', 'Within a week', 'When they call again'], 'answer' => 0],
                    ['question' => 'Where is our shop?', 'options' => ['Moi Avenue, Nairobi', 'Mombasa Road', 'Westlands'], 'answer' => 0],
                ],
            ],
        ];
    }

    /** One post per Updates tab. The recognition needs a real person, chosen by the owner at load time. */
    public static function posts(): array
    {
        return [
            'update' => [
                'title' => 'Welcome to the Bethany House hub',
                'must_read' => true,
                'body' => <<<'TXT'
From today, our working day lives on the hub, under My Workday in the menu.

What to do this week:
1. Read the handbook article "Using the hub every day".
2. Complete the training "Hub basics: your first week".
3. Sign the policies waiting for you under To sign.
4. Clock in when you arrive and out when you leave.
5. Open and close the shop with the checklist.

Questions? Comment below or ask your manager. Thank you for making Bethany House what it is.
TXT,
            ],
            'event' => [
                'title' => 'Advent begins: our busiest weeks of the year',
                'event_at' => '2026-11-29 09:00:00',
                'event_place' => 'The shop, Moi Avenue',
                'body' => <<<'TXT'
The first Sunday of Advent is 29 November, and Christmas is on a Friday this year. From now until Christmas, parishes order vestments in Advent and Christmas colours, communion ware, and gifts for their clergy and congregations.

What this means for us:
- Quotations go out the same day.
- Made-to-measure orders: take measurements early and give honest ready dates.
- Check stock on the hub before you promise.
- Keep the shop, the displays and the altar ware spotless.

Let us serve the Church well this season.
TXT,
            ],
            'celebration' => [
                'title' => 'The hub is live',
                'body' => <<<'TXT'
Today we start working from one place: sales, orders, our days and our team, all on the hub.

Celebrations appear here by themselves: birthdays (if you choose to share yours on the Colleagues page) and work anniversaries, from the dates in your staff file.
TXT,
            ],
            'recognition' => [
                'title' => null,
                // The owner writes who and why; this is the shape a good one takes.
                'body_template' => 'Thank you, {first_name}, for {what you did}. It made a difference to {who it helped}.',
            ],
        ];
    }

    /** First to-dos, given to each active staff member. */
    public static function todos(): array
    {
        return [
            ['title' => 'Read "Using the hub every day" in the handbook', 'details' => 'Handbook > Start here. Five minutes, and the rest of the hub makes sense.'],
            ['title' => 'Complete the training "Hub basics: your first week"', 'details' => 'Training. Four short lessons and a quiz.'],
            ['title' => 'Sign the policies waiting for you', 'details' => 'To sign. Read each one, then sign on your phone.'],
            ['title' => 'Choose what colleagues see about you', 'details' => 'Colleagues > "What colleagues see about you": your phone, your email, your birthday.'],
        ];
    }

    /** Items added to the starter checklists (only while they are still exactly as first installed). */
    public static function checklistAdditions(): array
    {
        return [
            'Opening checklist' => [
                ['key' => 'orders', 'label' => "Today's web and WhatsApp orders checked on the hub", 'type' => 'check', 'required' => true],
                ['key' => 'altar_ware', 'label' => 'Altar ware and display cases dusted and polished', 'type' => 'check', 'required' => false],
            ],
            'Closing checklist' => [
                ['key' => 'orders_tagged', 'label' => 'Customer orders and garments in production put away, each tagged with its order number', 'type' => 'check', 'required' => false],
            ],
        ];
    }

    /** A start-of-day checklist for each chat/WhatsApp desk (the shop checklists no longer apply there). */
    public static function chatDeskChecklist(): array
    {
        return [
            'name' => 'Chat desk: start of day',
            'due_time' => '09:30',
            'items' => [
                ['key' => 'overnight', 'label' => 'Overnight chats read; every flagged chat answered or handed over', 'type' => 'check', 'required' => true],
                ['key' => 'proofs', 'label' => 'Payment proofs uploaded overnight checked and confirmed or queried', 'type' => 'check', 'required' => true],
                ['key' => 'quotes', 'label' => 'Quotations promised for today sent', 'type' => 'check', 'required' => true],
                ['key' => 'waiting', 'label' => 'Chats still waiting for an answer', 'type' => 'number', 'required' => false],
            ],
        ];
    }
}
