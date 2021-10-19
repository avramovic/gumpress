# gumpress
Licensing WordPress themes and plugins through Gumroad made easy!

## Modules

We use term "modules" for both themes and plugins, and it's not to confuse your, it's just easier to use "module" instead of "theme and/or plugin" every time we try to explain how to use GumPress. So be prepared to see the word "module" throughout our documentation and code.

## Find your Gumroad product ID

We work with Gumroad licensing system, so make sure you've set up your product on Gumroad and find your product ID. Once you have found it, memorise it (or copy it somewhere in your computer) as you'll need it for our integration.

## Download the PHP class

Download GumPress.php file from this repository and put it in your module folder, next to your main module PHP file (plugin-name/plugin-name.php or theme-name/functions.php). Next, we need to load the class (if it's not already loaded) and initialise it in the said file.

## Class loading and initialisation

For the sake of our example, let's say our Gumroad product ID is "YOUR_GUMROAD_ID". In reality, you'd replace that with your real product ID. The integration code, which you should put at the very top of your main module PHP file (right after "<?php"), would look like this:

```
!class_exists('GumPress')) or require_once(dirname(__FILE__)."/GumPress.php");

GumPress::register(__FILE__, 'YOUR_GUMROAD_ID');
```

That's it? Yes! Well... yes and no. While this initialises the license checking system and a license page for your module, it doesn't do anything else. In other words, invalid licenses won't prevent your users from using your module or any of the "pro" options your module may offer. That's up to you, but in most cases you'd check it like this:

```
if (GumPress::for('YOUR_GUMROAD_ID')->is_valid_license() {
    // do stuff when license is VALID
} else {
    // do stuff when license is INVALID
}
```

And now that's it... sort of. There's more you can learn about [configuring GumPress](https://gumpress.tawk.help/article/configuring-gumpress), to fine-tune it for your needs. Also, license enforcement is completely up to you!
