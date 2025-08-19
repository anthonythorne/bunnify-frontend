# Fragement Cache Key WP Query Args

This class helps build cache key from the wp query args provided. This cache key can only be used with md5 hash so
that the limitation of characters isn't reached.

This ensures that no matter how an array of query args is delivered that it's sorted into the same order if it
has the same values in any provided order.
