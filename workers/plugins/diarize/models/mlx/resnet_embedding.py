"""
Fixed MLX ResNet34 speaker embedding.
Bug fix: Conv2d needs bias=False (WeSpeaker/PyTorch uses bias=False with BatchNorm).
"""
import mlx.core as mx
import mlx.nn as nn
import re


class BasicBlock(nn.Module):
    expansion = 1

    def __init__(self, in_channels, out_channels, stride=1):
        super().__init__()
        self.conv1 = nn.Conv2d(in_channels, out_channels, kernel_size=3,
                               stride=stride, padding=1, bias=False)  # FIX
        self.bn1 = nn.BatchNorm(out_channels)
        self.conv2 = nn.Conv2d(out_channels, out_channels, kernel_size=3,
                               stride=1, padding=1, bias=False)  # FIX
        self.bn2 = nn.BatchNorm(out_channels)

        self.use_shortcut = stride != 1 or in_channels != out_channels
        if self.use_shortcut:
            self.shortcut_conv = nn.Conv2d(in_channels, out_channels,
                                          kernel_size=1, stride=stride, padding=0, bias=False)  # FIX
            self.shortcut_bn = nn.BatchNorm(out_channels)

    def __call__(self, x):
        identity = x
        out = nn.relu(self.bn1(self.conv1(x)))
        out = self.bn2(self.conv2(out))
        if self.use_shortcut:
            identity = self.shortcut_bn(self.shortcut_conv(identity))
        return nn.relu(out + identity)


class ResNet34Embedding(nn.Module):
    def __init__(self, feat_dim=80, embed_dim=256, m_channels=32):
        super().__init__()
        self.conv1 = nn.Conv2d(1, m_channels, kernel_size=3, stride=1, padding=1, bias=False)  # FIX
        self.bn1 = nn.BatchNorm(m_channels)

        self.layer1 = nn.Sequential(*[BasicBlock(m_channels, m_channels) for _ in range(3)])
        self.layer2 = nn.Sequential(*[BasicBlock(m_channels if i == 0 else m_channels*2,
                                                  m_channels*2,
                                                  stride=2 if i == 0 else 1) for i in range(4)])
        self.layer3 = nn.Sequential(*[BasicBlock(m_channels*2 if i == 0 else m_channels*4,
                                                  m_channels*4,
                                                  stride=2 if i == 0 else 1) for i in range(6)])
        self.layer4 = nn.Sequential(*[BasicBlock(m_channels*4 if i == 0 else m_channels*8,
                                                  m_channels*8,
                                                  stride=2 if i == 0 else 1) for i in range(3)])
        self.fc = nn.Linear(m_channels * 8 * 2, embed_dim)  # *2 for mean+std pooling

    def __call__(self, x):
        if x.ndim == 3:
            x = mx.expand_dims(x, axis=-1)
        x = mx.transpose(x, (0, 2, 1, 3))  # (B,T,F,1) → (B,F,T,1)
        x = nn.relu(self.bn1(self.conv1(x)))
        x = self.layer1(x)
        x = self.layer2(x)
        x = self.layer3(x)
        x = self.layer4(x)
        # Temporal stats pooling over time (axis=2)
        # x shape: (B, F', T', C) in MLX NHWC format
        mean = mx.mean(x, axis=2)  # (B, F', C)
        std = mx.sqrt(mx.var(x, axis=2) + 1e-7)  # (B, F', C)
        # PyTorch flattens as (B, C, F') → (B, C*F'). 
        # MLX has (B, F', C), so transpose to (B, C, F') to match.
        mean = mx.transpose(mean, (0, 2, 1))  # (B, C, F')
        std = mx.transpose(std, (0, 2, 1))    # (B, C, F')
        mean = mean.reshape(mean.shape[0], -1)  # (B, C*F')
        std = std.reshape(std.shape[0], -1)      # (B, C*F')
        x = mx.concatenate([mean, std], axis=1)  # (B, 2*C*F')
        return self.fc(x)


def load_model(weights_path):
    model = ResNet34Embedding()
    weights = mx.load(str(weights_path))

    mapped = {}
    for key, value in weights.items():
        if not key.startswith('resnet.'):
            continue
        new_key = key[7:]  # strip 'resnet.'
        new_key = new_key.replace('.shortcut.0.', '.shortcut_conv.')
        new_key = new_key.replace('.shortcut.1.', '.shortcut_bn.')
        if new_key.startswith('seg_1.'):
            new_key = new_key.replace('seg_1.', 'fc.')
        new_key = re.sub(r'(layer[1-4])\.(\d+)\.', r'\1.layers.\2.', new_key)
        mapped[new_key] = value

    # Load into model
    model_params = dict(nn.utils.tree_flatten(model.parameters()))
    loaded = 0
    missing = []

    for key, value in mapped.items():
        try:
            path = key.split('.')
            module = model
            for attr in path[:-1]:
                if attr.isdigit():
                    module = module[int(attr)]
                elif attr == 'layers':
                    module = module.layers
                else:
                    module = getattr(module, attr)
            setattr(module, path[-1], value)
            loaded += 1
        except Exception as e:
            pass

    for key in model_params:
        if key not in mapped:
            if not any(x in key for x in ['running_mean', 'running_var']):
                missing.append(key)

    model.eval()
    print(f"Loaded {loaded}/{len(mapped)} params, missing: {len(missing)}")
    if missing:
        for m in missing[:5]:
            print(f"  MISSING: {m}")
    return model
