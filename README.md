# Support Candy to KB Support Migrator

WordPress WP CLI tool which maps [SupportCandy](https://wordpress.org/plugins/supportcandy/) helpdesk tickets to [KB Support](https://wordpress.org/plugins/kb-support/) tickets.

Why? The SupportCandy UI is a JavaScript UI which doesn't allow having more than one ticket open at a time. The KB Support UI uses WordPress's native custom post type UI which is familiar and easily extensible. 

IIRC, this was used to migrate ~4,000 tickets. 

# NB

This is very much a developer tool. This is probably not useful to 99% of people unless they write a lot of code themselves.

> ⚠️ The code is the documentation, and it is incomplete.

This only migrates the tickets, it does not transfer settings.

A lot of WPCS and PhpStan was done _after_ it was last used.

## How to use

Migrations are processes you need to test repeatedly until you are confident in them. 

Set up a local instance of WordPress containing the database with the SupportCandy tickets you want to migrate, with only SupportCandy, KB Support, and related plugins (e.g. WooCommerce) active. Once configured for development, create a database backup which can quickly be restored.

Being to  iterate.

### Run

`wp wpsc_kbs_migrator --help`

This is a CLI tool. By default `--dry-run` is `true`.

To begin, run the tool on some of the tickets. It will identify unmapped categories and metadata and advise on how to deal with it.

`wp wpsc_kbs_migrator move_tickets --count=100 --debug=bh-wp-support-candy-to-kb-support-migrator`

Where the tool does not know what to do, it prompts with the issue and sometimes as suggestion. Often, this will be left to the developer to figure out.

```
Warning: {wpsc:321,thread_wp_post:1123,wp_post:1123} : No mapping from SupportCandy category: `post-order-query` to KBS category. Use `wp term create ticket_category "Post-order query" --slug=post-order-query`.
```

Repeatedly run the same command, increasing the count or using `--all`.

You need to identify what data is important and what is not... i.e. what aspects of SupportCandy were you using which I was not, and what noise is in your WordPress install that I did not need to deal with.

### Unsorted notes 

It's so long since I used this, I don't remember enough to comment everything:

```
# Find WPSC tickets with attachments
wp db query "DELETE FROM wp_postmeta WHERE meta_key = 'attachments' AND meta_value = 'a:0:{}';"
wp db query "SELECT * FROM wp_postmeta WHERE meta_key = 'attachments' LIMIT 10;"
wp db query "SELECT post_id FROM wp_postmeta WHERE meta_key = 'attachments' LIMIT 1;"

wp post meta get <post_id> ticket_id
wp wpsc_kbs_migrator move_tickets <ticket_id> --dry-run=false

wp search-replace <filename> found wp_term* --dry-run --log
wp db query "SELECT * FROM wp_termmeta WHERE meta_key = 7123"
wp db query "SELECT * FROM wp_termmeta WHERE meta_value = '<filename>'"
wp db query "SELECT * FROM wp_postmeta WHERE meta_key = 'attachments' AND meta_value LIKE '<term_id>'"

wp db query "SELECT * FROM wp_postmeta WHERE meta_key = 'ticket_id' AND meta_value = '0'"

wp user update 1 --user_pass=password --user_nicename=admin 

wp db query "UPDATE wp_posts SET post_status = 'publish' WHERE post_type = 'kbs_ticket_reply'"

# NB: Need to set the "last checked" time for the emails.
kbs_ems_get_email_server_connections()

$connections = get_option( 'kbs_ems_email_server_connections', array() );
last_poll

// TODO: Get the last ticket time.
SELECT post_date FROM `wp_posts` WHERE post_type IN ('kbs_ticket','kbs_ticket_reply') ORDER BY ID DESC LIMIT 1
$connections[array_key_first($connections)]['last_poll'] = "2022-08-17 16:35:09";
update_option( 'kbs_ems_email_server_connections', $connections );

_kbs_ticket_woo_order
DELETE FROM `wp_wpsc_ticketmeta` WHERE meta_key = 'to_email'
```

# Contributing

I'm happy to work with anyone who needs this if we can build something better together.

# See Also

* [bh-wp-kbs-ticket-priorities](https://github.com/BrianHenryIE/bh-wp-kbs-ticket-priorities) – Adds a priority field for KB Support, WordPress ticketing system (actually important here if you used priorities in SupportCandy)
* [KBS Omniform](https://github.com/BrianHenryIE/bh-wp-kbs-omniform) – Pipe form submission from [Omniform](https://omniform.io/) into KB Support
