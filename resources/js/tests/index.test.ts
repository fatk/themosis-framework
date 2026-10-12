export {};

// This entry API test runs in Node; WordPress UI packages require a browser at import time.
jest.mock('@wordpress/components', () => ({}));
jest.mock('react-dom', () => ({}));
(global as any).window = {addEventListener: jest.fn()};

const themosis = require("../index").default;

afterAll(() => { delete (global as any).window; });

test('Hello Themosis', () => {
    expect(themosis.hello()).toBe('Themosis Framework');
});
