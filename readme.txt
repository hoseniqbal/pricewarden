=== PriceWarden: Wrong Price Protection for WooCommerce ===
Contributors: iqbal511138
Tags: woocommerce, price, pricing error, price check, alerts
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Learns the normal price of every product and stops checkouts at zero or far below it. Emails you when an edit or import drops a price.

== Description ==

A product shows up at $0 after an import. A sale price is typed as 9.9 instead of 99. A currency plugin glitches. By the time you notice, customers have bought at the wrong price and you either lose money or cancel orders and upset people.

PriceWarden watches every price for you.

= What it does =

* **Learns normal prices.** On activation it records the current price of every product and variation. Price rises are accepted at once. A lower price becomes normal when you confirm it, or automatically after a few days.
* **Stops wrong-price checkouts.** If a product in the cart costs 0, or much less than its normal price (more than 70% under by default), the customer sees a friendly message and the order is not placed. Works on the cart, the classic checkout and the block checkout. Staff are never stopped.
* **Price drop emails.** When a save, import or bulk edit lowers a price a lot, you get one email listing the products, who changed them, and a link to confirm the new price.
* **Price check.** A daily scan lists zero prices, missing prices, sale prices that are not lower than the regular price and prices far below normal, with one-click "Price is right" and "Free on purpose" buttons.
* **Dashboard** with checkouts stopped and the money they saved.

= Privacy =

PriceWarden stores product IDs, prices and the staff member who changed them. No customer details. No external requests.

== Installation ==

1. Install and activate PriceWarden. WooCommerce must be active.
2. Open PriceWarden, Price check, and look at anything it lists.

== Frequently Asked Questions ==

= I run a big sale. Will customers be stopped? =

Only if the price is more than 70% under normal (you can change this). Confirm the sale price with the link in the email or on Price check, and it is allowed.

= I give some products away for free. =

Use "Free on purpose" on the Price check page.

= Does it change my prices? =

Never. It only stops the checkout and tells you.

== Changelog ==

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.0 =
First release.
