<?php

namespace Database\Seeders;

use App\Models\Faq;
use Illuminate\Database\Seeder;

class FaqSeeder extends Seeder
{
    public function run(): void
    {
        $faqs = [
            [
                'What is FGX MART?',
                'FGX MART is a catalogue-based shopping platform for packed food, clothing, electronics and kitchen utensils. Browse products, add your selections to the cart, download a PDF proforma invoice and share your requirements with our team on WhatsApp.',
            ],
            [
                'How do I find the products I need?',
                'Use the search bar or browse All Categories to explore main categories and subcategories. You can also visit Hot Deals, New Arrivals and Best Sellers, then open a product to view its images, description and available options.',
            ],
            [
                'Do I need an account to use FGX MART?',
                'You can browse products and add items to your cart as a guest. Sign in or create an account to continue to checkout, manage your saved addresses, view your orders and submit product reviews.',
            ],
            [
                'How do I add products and choose quantities?',
                'Select Add to cart on a product card or product page. If a product has options, choose the option you need before adding it. Open your cart to review your selections, change quantities or remove items before continuing.',
            ],
            [
                'How do I select my country and zipcode?',
                'Choose your country in the location popup when you first visit. If zipcodes are configured for that country, select a zipcode in the next step. You can change your shopping location from the header. Your selection is remembered in this browser; signed-in customers with saved addresses use their default address location.',
            ],
            [
                'Why am I being asked to sign in at checkout?',
                'Checkout uses your customer account to associate your details, delivery address and order with you. After signing in, you can continue with your selected products. If you do not have an account, choose Create account on the login page.',
            ],
            [
                'How do I download a PDF of my selected products?',
                'Add the products and quantities you need to your cart, then sign in and continue to checkout. Use Download Proforma Invoice to save a PDF of your selection. Review the product details, quantities and totals before sharing it with our team.',
            ],
            [
                'How do I send my product enquiry on WhatsApp?',
                'Download your PDF proforma invoice from checkout, then use WhatsApp Support to open a conversation with FGX MART. Attach the downloaded PDF in WhatsApp and include any questions or special requirements. Opening WhatsApp does not automatically attach or send the PDF.',
            ],
            [
                'Does adding a product to my cart reserve stock?',
                'Adding a product to your cart saves your selection but does not reserve stock. Availability, quantities and product options may change. Confirm the details of your requirements with the FGX MART team before proceeding.',
            ],
            [
                'Are the displayed prices and PDF totals final?',
                'Use the displayed prices and proforma invoice to discuss your requirements with our team. Ask FGX MART to confirm current pricing, availability and any applicable delivery charges or taxes for your destination before finalising your request.',
            ],
            [
                'Can you deliver to my country or zipcode?',
                'Select your country and, where available, your zipcode to identify your shopping location. Share your full delivery address with our team so they can confirm whether your selected products can be supplied to that destination. A country or zipcode appearing in the list does not by itself guarantee delivery for every product.',
            ],
            [
                'How can I confirm delivery charges and delivery time?',
                'Delivery charges and timing depend on your destination, selected products and quantities. Contact our team with your product list and delivery location for confirmation. Please do not assume a fixed delivery date from the catalogue alone.',
            ],
            [
                'How do I check an order I have already placed?',
                'Sign in and open Track order or the Orders section in My account to view the information available for your orders. If you need clarification, contact our team and include your order reference.',
            ],
            [
                'What should I do if I need to change my request or report a problem?',
                'Contact FGX MART as soon as possible through WhatsApp Support. Include your order or enquiry reference, the product name and a description of the change or issue. For damaged or incorrect items, include photos where helpful. Our team will confirm the applicable next steps and any return or replacement arrangements.',
            ],
            [
                'How can I save products to view later?',
                'Use the heart icon on a product to add it to your Wishlist. Open Wishlist from the header to review your saved products. You can remove a saved product by selecting its heart icon again.',
            ],
            [
                'Who can leave a product review?',
                'Registered customers can sign in, open a product page and choose Write a review. Select a rating from one to five stars and share a comment about your experience. You can edit your existing review for that product; only published reviews contribute to the displayed rating.',
            ],
        ];

        foreach ($faqs as $index => [$title, $description]) {
            // Keep existing descriptions, visibility and ordering managed by administrators.
            Faq::firstOrCreate(['title' => $title], [
                'description' => $description,
                'sort_order' => ($index + 1) * 10,
                'status' => true,
            ]);
        }
    }
}
