using JetBrains.Annotations;
using MusicLyricApp.Core.Utils;
using MusicLyricApp.Models;

namespace MusicLyricAppTest.Core.Utils;

[TestSubject(typeof(GlobalUtils))]
public class GlobalUtilsTest
{
    [Fact]
    public void TestConvertSearchWithShareLink_SongId()
    {
        var origin = "https://i.y.qq.com/v8/playsong.html?songid=107762031&songtype=0#webchat_redirect";
        var target = "https://i.y.qq.com/v8/songDetail/107762031";

        Assert.Equal(target, GlobalUtils.ConvertSearchWithShareLink(SearchSourceEnum.QQ_MUSIC, origin));
        Assert.Equal(origin, GlobalUtils.ConvertSearchWithShareLink(SearchSourceEnum.NET_EASE_MUSIC, origin));
    }

    [Fact]
    public void TestConvertSearchWithShareLink_AlbumId()
    {
        var origin = "https://i.y.qq.com/n2/m/share/details/album.html?albummid=003RL1Hk0lf62Q";
        var target = "https://i.y.qq.com/n2/m/share/details/albumDetail/003RL1Hk0lf62Q";

        Assert.Equal(target, GlobalUtils.ConvertSearchWithShareLink(SearchSourceEnum.QQ_MUSIC, origin));
        Assert.Equal(origin, GlobalUtils.ConvertSearchWithShareLink(SearchSourceEnum.NET_EASE_MUSIC, origin));
    }
    
    [Fact]
    public void TestConvertSearchWithShareLink_AlbumId2()
    {
        var origin = "https://i.y.qq.com/n2/m/share/details/album.html?ADTAG=pc_v17&albumId=36316266&channelId=10036163&openinqqmusic=1";
        var target = "https://i.y.qq.com/n2/m/share/details/albumDetail/36316266&channelId=10036163&openinqqmusic=1";

        Assert.Equal(target, GlobalUtils.ConvertSearchWithShareLink(SearchSourceEnum.QQ_MUSIC, origin));
        Assert.Equal(origin, GlobalUtils.ConvertSearchWithShareLink(SearchSourceEnum.NET_EASE_MUSIC, origin));
    }

    [Fact]
    public void TestConvertSearchWithShareLink_PlaylistId()
    {
        var origin = "https://i.y.qq.com/n2/m/share/details/taoge.html?id=7581901981&hosteuin=";
        var target = "https://i.y.qq.com/n2/m/share/details/playlist/7581901981";

        Assert.Equal(target, GlobalUtils.ConvertSearchWithShareLink(SearchSourceEnum.QQ_MUSIC, origin));
        Assert.Equal(origin, GlobalUtils.ConvertSearchWithShareLink(SearchSourceEnum.NET_EASE_MUSIC, origin));
    }

    [Fact]
    public void TestConvertSearchWithShareLink_NonQQMusic()
    {
        // Arrange
        var searchSource = SearchSourceEnum.NET_EASE_MUSIC;
        var input = "https://i.y.qq.com/v8/playsong.html?songid=107762031&songtype=0#webchat_redirect";
        var expected = input; // Should remain unchanged for non-QQ music

        // Act
        var actual = GlobalUtils.ConvertSearchWithShareLink(searchSource, input);

        // Assert
        Assert.Equal(expected, actual);
    }

    [Fact]
    public void TestConvertSearchWithShareLink_OtherQQMusicLink()
    {
        // Arrange
        var searchSource = SearchSourceEnum.QQ_MUSIC;
        var input = "https://y.qq.com/n/ryqq/songDetail/003ODsL83GwKj4";
        var expected = input; // Should remain unchanged if it doesn't match any patterns

        // Act
        var actual = GlobalUtils.ConvertSearchWithShareLink(searchSource, input);

        // Assert
        Assert.Equal(expected, actual);
    }
}