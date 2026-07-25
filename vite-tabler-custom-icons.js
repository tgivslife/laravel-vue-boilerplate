/*
 * Brand icons that don't exist in the tabler set, merged into the
 * `virtual:tabler-icons` collection by vite-tabler-icons.js so they are
 * referenced with the same `i-tabler-{name}` spelling as every other
 * icon in the app. The ROeID mark keeps its brand blue while the ink
 * strokes follow currentColor, so it reads correctly in both color
 * schemes.
 */
export default {
    roeid: {
        body: '<g fill="none" stroke-width="1.68" stroke-linecap="round" stroke-linejoin="round">'
            + '<path stroke="#3B68FF" d="M 20.93 9.19 A 9.36 9.36 0 0 0 15.2 3.2"/>'
            + '<path stroke="#3B68FF" d="M 14.42 21.04 A 9.36 9.36 0 0 0 20.93 14.81"/>'
            + '<path stroke="#3B68FF" d="M 9.58 2.96 A 9.36 9.36 0 0 0 8.8 20.8"/>'
            + '<path stroke="currentColor" d="M 12 12 L 18.24 12 A 6.24 6.24 0 1 0 7.22 16.01"/>'
            + '<path stroke="currentColor" d="M 10.38 18.03 A 6.24 6.24 0 0 0 17.11 15.58"/>'
            + '<path stroke="#3B68FF" d="M 14.12 9.88 A 3 3 0 1 0 14.12 14.12"/>'
            + '</g>',
        width: 24,
        height: 24,
    },
}
