<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    private array $valid = ['name' => 'Sara Khan', 'email' => 'sara@example.com', 'business' => 'Bloom', 'topic' => 'sales', 'message' => 'How do I connect this to my booking system?'];

    public function test_visitor_can_send_a_contact_message(): void
    {
        $this->from('/')->post('/contact', $this->valid)->assertRedirect('/')->assertSessionHas('success');

        $msg = ContactMessage::sole();
        $this->assertSame('new', $msg->status);
        $this->assertSame('Sales question', $msg->topicLabel());
    }

    public function test_contact_form_is_validated(): void
    {
        $this->from('/')->post('/contact', ['name' => '', 'email' => 'nope', 'topic' => 'spam', 'message' => 'short'])
            ->assertSessionHasErrors(['name', 'email', 'topic', 'message']);
        $this->assertSame(0, ContactMessage::count());
    }

    public function test_honeypot_submissions_are_dropped_silently(): void
    {
        $this->from('/')->post('/contact', $this->valid + ['company_site' => 'http://spam.test'])->assertSessionHas('success');
        $this->assertSame(0, ContactMessage::count());
    }

    public function test_only_admin_can_read_and_manage_messages(): void
    {
        $msg = ContactMessage::create($this->valid);

        $this->actingAs(User::factory()->create())->get('/admin/messages')->assertForbidden();

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/messages')
            ->assertInertia(fn ($page) => $page->component('Admin/Messages')->has('messages', 1)->where('auth.badges.newMessages', 1));

        $this->actingAs($admin)->post("/admin/messages/{$msg->id}/read")->assertSessionHas('success');
        $this->assertSame('read', $msg->fresh()->status);

        $this->actingAs($admin)->delete("/admin/messages/{$msg->id}")->assertSessionHas('success');
        $this->assertSame(0, ContactMessage::count());
    }
}
