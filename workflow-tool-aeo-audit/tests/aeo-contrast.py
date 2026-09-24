"""Check the actual report palette for readable status text and visible borders."""
from pathlib import Path
import re
css = (Path(__file__).parents[1] / 'assets/aeo-report.css').read_text()
def luminance(hex_color):
    channels = [int(hex_color[i:i+2],16)/255 for i in (1,3,5)]
    linear = [v/12.92 if v <= 0.04045 else ((v+0.055)/1.055)**2.4 for v in channels]
    return sum(v*w for v,w in zip(linear,[0.2126,0.7152,0.0722]))
def contrast(a,b):
    x,y=sorted([luminance(a),luminance(b)])
    return (y+0.05)/(x+0.05)
for name,selector in [('pass','__issue--pass'),('fail','__issue--fail'),('neutral','__issue-text')]:
    body=re.search(re.escape(selector)+r'\s*\{([^}]+)',css).group(1)
    foreground=re.search(r'(?:^|[;\n])\s*color:\s*(#[\da-f]+)',body).group(1)
    background=re.search(r'background:\s*(#[\da-f]+)',body).group(1)
    ratio=contrast(foreground,background)
    assert ratio >= 4.5, (name,ratio)
    print(f'{name}: text contrast {ratio:.2f}:1 (AA normal text passes)')
    if name!='neutral':
        border=re.search(r'border-color:\s*(#[\da-f]+)',body).group(1)
        assert contrast(border,background)>=3, name+' border'
print('PASS: report contrast checks')
