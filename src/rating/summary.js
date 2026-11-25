/**
 * Text helpers for the front end. Pure functions, so they can be tested
 * without a browser.
 *
 * The strings are the ones PHP rendered, translated, so a rebuilt summary reads
 * the same as the server-rendered one.
 */

/**
 * Fill %s and %1$s style placeholders, as the PHP side writes them.
 *
 * @param {string}    template Template with placeholders.
 * @param {...string} args     Values, in order.
 * @return {string} The filled string.
 */
export function format( template, ...args ) {
	let next = 0;

	return template.replace( /%(?:(\d+)\$)?s/g, ( match, position ) =>
		String( args[ position ? Number( position ) - 1 : next++ ] )
	);
}

/**
 * Format a number for a locale. Falls back to a plain string if the locale is
 * not one Intl accepts.
 *
 * @param {number} value  The number.
 * @param {string} locale BCP 47 tag, as in the html lang attribute.
 * @param {number} digits Fraction digits to show.
 * @return {string} The formatted number.
 */
export function formatNumber( value, locale, digits ) {
	try {
		return new Intl.NumberFormat( locale, {
			minimumFractionDigits: digits,
			maximumFractionDigits: digits,
		} ).format( value );
	} catch {
		return value.toFixed( digits );
	}
}

/**
 * The summary line for a rating.
 *
 * With no votes the average is null and the line says so. It never shows a
 * zero average or zero stars.
 *
 * @param {Object}      summary           Summary values.
 * @param {number|null} summary.average   Average score, or null.
 * @param {number}      summary.count     Number of votes.
 * @param {boolean}     summary.showCount Whether to include the count.
 * @param {Object}      strings           Translated templates from the server.
 * @param {string}      locale            Locale for number formatting.
 * @return {string} The summary text.
 */
export function buildSummary( { average, count, showCount }, strings, locale ) {
	if ( average === null || average === undefined ) {
		return strings.noRatings;
	}

	const formattedAverage = formatNumber( average, locale, 1 );

	if ( ! showCount ) {
		return format( strings.averageOnly, formattedAverage );
	}

	return format(
		strings.averageWithCount,
		formattedAverage,
		formatNumber( count, locale, 0 )
	);
}
