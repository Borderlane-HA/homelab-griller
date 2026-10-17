#!/usr/bin/env python3
"""Guard against unreadable theme palettes (WCAG 2.x AA normal text)."""
from pathlib import Path
import re

css = (Path(__file__).resolve().parents[1] / 'app/assets/style.css').read_text()
themes = ('ember', 'smoke', 'garden', 'nordic', 'midnight', 'kids')

def luminance(hex_color):
    channels = [int(hex_color[i:i+2], 16)/255 for i in (1, 3, 5)]
    r, g, b = [(x/12.92 if x <= 0.04045 else ((x+0.055)/1.055)**2.4) for x in channels]
    return r*0.2126 + g*0.7152 + b*0.0722

def contrast(a, b):
    lighter, darker = sorted((luminance(a), luminance(b)), reverse=True)
    return (lighter+0.05)/(darker+0.05)

for theme in themes:
    # Select the last token declaration, as beta.6 intentionally overrides beta.5 palettes.
    matches = re.findall(r'body\.theme-'+re.escape(theme)+r'\s*\{([^}]+)\}', css)
    if not matches:
        raise AssertionError(f'Missing theme {theme}')
    tokens = dict(re.findall(r'(--[\w-]+):\s*(#[\da-fA-F]{3,6})(?![\da-fA-F])', matches[-1]))
    tokens = {key: ('#'+''.join(c*2 for c in v[1:]) if len(v)==4 else v) for key,v in tokens.items()}
    for key in ('--card', '--text', '--muted', '--brand-text', '--brand', '--accent-fg', '--green', '--blue', '--red'):
        assert key in tokens, f'{theme}: missing {key}'
    for foreground in ('--text', '--muted', '--brand-text', '--green', '--blue', '--red'):
        result = contrast(tokens[foreground], tokens['--card'])
        assert result >= 4.5, f'{theme}: {foreground} has only {result:.2f}:1 contrast'
    result = contrast(tokens['--brand'], tokens['--accent-fg'])
    assert result >= 4.5, f'{theme}: button has only {result:.2f}:1 contrast'
    print(f'{theme}: text and controls meet 4.5:1 contrast')
print('Theme palette checks passed.')
