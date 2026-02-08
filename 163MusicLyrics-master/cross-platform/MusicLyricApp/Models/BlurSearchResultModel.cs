using System.Collections.Generic;
using Avalonia.Controls;
using Avalonia.Data;
using CommunityToolkit.Mvvm.ComponentModel;
using MusicLyricApp.Core.Utils;

namespace MusicLyricApp.Models;

public abstract partial class BlurSearchResultBase : ObservableObject
{
    [ObservableProperty] private string? _id;
    [ObservableProperty] private string? _title;
    [ObservableProperty] private string? _author;
    [ObservableProperty] private string? _searchSource;
}

public partial class BlurSongSearchResult : BlurSearchResultBase
{
    [ObservableProperty] private string? _album;
    [ObservableProperty] private string? _duration;

    public BlurSongSearchResult(SearchSourceEnum searchSource, SearchResultVo.SongSearchResultVo resultVo)
    {
        var idPrefix = GlobalUtils.SearchSourceKeywordDict[searchSource] + "/" +
                       GlobalUtils.SearchTypeKeywordDict[searchSource][SearchTypeEnum.SONG_ID];

        Id = idPrefix + resultVo.DisplayId;
        Title = resultVo.Title;
        Author = string.Join(",", resultVo.AuthorName);
        Album = resultVo.AlbumName;
        Duration = new LyricTimestamp(resultVo.Duration).PrintTimestamp("mm:ss", DotTypeEnum.DOWN);
        SearchSource = searchSource.ToDescription();
    }

    public static List<DataGridTextColumn> GetDataGridColumns()
    {
        return
        [
            new DataGridTextColumn
            {
                Header = "歌曲", Binding = new Binding(nameof(Title)),
                Width = new DataGridLength(3, DataGridLengthUnitType.Star)
            },

            new DataGridTextColumn
            {
                Header = "歌手", Binding = new Binding(nameof(Author)),
                Width = new DataGridLength(1, DataGridLengthUnitType.Star)
            },

            new DataGridTextColumn
            {
                Header = "专辑", Binding = new Binding(nameof(Album)),
                Width = new DataGridLength(2, DataGridLengthUnitType.Star)
            },

            new DataGridTextColumn
            {
                Header = "时长", Binding = new Binding(nameof(Duration)),
                Width = new DataGridLength(1, DataGridLengthUnitType.Star)
            },

            new DataGridTextColumn
            {
                Header = "平台", Binding = new Binding(nameof(SearchSource)),
                Width = new DataGridLength(1, DataGridLengthUnitType.Star)
            }
        ];
    }
}

public partial class BlurAlbumSearchResult : BlurSearchResultBase
{
    [ObservableProperty] private string? _songCount;
    [ObservableProperty] private string? _publishTime;

    public BlurAlbumSearchResult(SearchSourceEnum searchSource, SearchResultVo.AlbumSearchResultVo resultVo)
    {
        var idPrefix = GlobalUtils.SearchSourceKeywordDict[searchSource] + "/" +
                       GlobalUtils.SearchTypeKeywordDict[searchSource][SearchTypeEnum.ALBUM_ID];

        Id = idPrefix + resultVo.DisplayId;
        Title = resultVo.AlbumName;
        Author = string.Join(",", resultVo.AuthorName);
        SongCount = resultVo.SongCount.ToString();
        PublishTime = resultVo.PublishTime;
        SearchSource = searchSource.ToDescription();
    }

    public static List<DataGridTextColumn> GetDataGridColumns()
    {
        return
        [
            new DataGridTextColumn
            {
                Header = "专辑", Binding = new Binding(nameof(Title)),
                Width = new DataGridLength(3, DataGridLengthUnitType.Star)
            },

            new DataGridTextColumn
            {
                Header = "歌手", Binding = new Binding(nameof(Author)),
                Width = new DataGridLength(2, DataGridLengthUnitType.Star)
            },

            new DataGridTextColumn
            {
                Header = "歌曲数量", Binding = new Binding(nameof(SongCount)),
                Width = new DataGridLength(1, DataGridLengthUnitType.Star)
            },

            new DataGridTextColumn
            {
                Header = "发行时间", Binding = new Binding(nameof(PublishTime)),
                Width = new DataGridLength(1, DataGridLengthUnitType.Star)
            },

            new DataGridTextColumn
            {
                Header = "平台", Binding = new Binding(nameof(SearchSource)),
                Width = new DataGridLength(1, DataGridLengthUnitType.Star)
            }
        ];
    }
}

public partial class BlurPlaylistSearchResult : BlurSearchResultBase
{
    [ObservableProperty] private string? _description;
    [ObservableProperty] private string? _songCount;
    [ObservableProperty] private string? _playCount;

    public BlurPlaylistSearchResult(SearchSourceEnum searchSource, SearchResultVo.PlaylistResultVo resultVo)
    {
        var idPrefix = GlobalUtils.SearchSourceKeywordDict[searchSource] + "/" +
                       GlobalUtils.SearchTypeKeywordDict[searchSource][SearchTypeEnum.PLAYLIST_ID];

        Id = idPrefix + resultVo.DisplayId;
        Title = resultVo.PlaylistName;
        Author = resultVo.AuthorName;
        Description = resultVo.Description;
        SongCount = resultVo.SongCount.ToString();
        PlayCount = resultVo.PlayCount.ToString();
        SearchSource = searchSource.ToDescription();
    }

    public static List<DataGridTextColumn> GetDataGridColumns()
    {
        return
        [
            new DataGridTextColumn
            {
                Header = "歌单名", Binding = new Binding(nameof(Title)),
                Width = new DataGridLength(2, DataGridLengthUnitType.Star)
            },

            new DataGridTextColumn
            {
                Header = "作者名", Binding = new Binding(nameof(Author)),
                Width = new DataGridLength(1, DataGridLengthUnitType.Star)
            },

            new DataGridTextColumn
            {
                Header = "描述", Binding = new Binding(nameof(Description)),
                Width = new DataGridLength(3, DataGridLengthUnitType.Star)
            },

            new DataGridTextColumn
            {
                Header = "歌曲数量", Binding = new Binding(nameof(SongCount)),
                Width = new DataGridLength(1, DataGridLengthUnitType.Star)
            },

            new DataGridTextColumn
            {
                Header = "播放量", Binding = new Binding(nameof(PlayCount)),
                Width = new DataGridLength(1, DataGridLengthUnitType.Star)
            },

            new DataGridTextColumn
            {
                Header = "平台", Binding = new Binding(nameof(SearchSource)),
                Width = new DataGridLength(1, DataGridLengthUnitType.Star)
            }
        ];
    }
}