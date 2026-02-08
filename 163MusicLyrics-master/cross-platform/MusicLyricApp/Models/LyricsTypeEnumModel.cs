using CommunityToolkit.Mvvm.ComponentModel;

namespace MusicLyricApp.Models;

public partial class LyricsTypeEnumModel(LyricsTypeEnum one) : ObservableObject
{
    public int Id { get; } = (int)one;
    public string Name { get; } = one.ToDescription();

    [ObservableProperty] private bool _isSelected;
}